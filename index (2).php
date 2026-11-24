<?php
/**
 * Task Manager — single-file PHP app (PHP 7.4+ with PDO SQLite)
 *
 * Run it:   php -S localhost:8000     (inside this folder)
 * Then open http://localhost:8000
 *
 * Tasks are stored in tasks.db, created automatically next to this file.
 * On a real web server (Apache/Nginx), make sure PHP can write to this folder.
 */

session_start();

/* ---------- Database ---------- */
$db = new PDO('sqlite:' . __DIR__ . '/tasks.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    priority TEXT NOT NULL DEFAULT 'medium',
    status TEXT NOT NULL DEFAULT 'todo',
    due_date TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

/* ---------- Helpers ---------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const PRIORITIES = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
const STATUSES   = ['todo' => 'To do', 'doing' => 'In progress', 'done' => 'Done'];

/* Keep the current filter/search/sort when redirecting after an action */
$filter = $_GET['filter'] ?? 'all';
$sort   = $_GET['sort'] ?? 'due';
$search = trim($_GET['q'] ?? '');
$validFilters = ['all', 'todo', 'doing', 'done', 'overdue'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';
if (!in_array($sort, ['due', 'priority', 'newest'], true)) $sort = 'due';

function viewUrl(array $override = []): string {
    global $filter, $sort, $search;
    $params = array_merge(['filter' => $filter, 'sort' => $sort, 'q' => $search], $override);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    if (($params['filter'] ?? '') === 'all') unset($params['filter']);
    if (($params['sort'] ?? '') === 'due') unset($params['sort']);
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

function flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }

/* ---------- Actions (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid request. Go back, refresh the page and try again.');
    }

    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $title    = trim($_POST['title'] ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        $status   = $_POST['status'] ?? 'todo';
        $due      = trim($_POST['due_date'] ?? '');

        $errors = [];
        if ($title === '') $errors[] = 'Enter a task title.';
        if (mb_strlen($title) > 120) $errors[] = 'Keep the title under 120 characters.';
        if (!isset(PRIORITIES[$priority])) $priority = 'medium';
        if (!isset(STATUSES[$status])) $status = 'todo';
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $errors[] = 'Pick a valid due date.';
        $due = $due === '' ? null : $due;

        if ($errors) {
            flash('error', implode(' ', $errors));
            $_SESSION['old'] = compact('title', 'desc', 'priority', 'status', 'due');
            header('Location: ' . viewUrl() . ($id ? (strpos(viewUrl(), '?') ? '&' : '?') . 'edit=' . $id : ''));
            exit;
        }

        if ($id) {
            $stmt = $db->prepare('UPDATE tasks SET title=?, description=?, priority=?, status=?, due_date=? WHERE id=?');
            $stmt->execute([$title, $desc, $priority, $status, $due, $id]);
            flash('ok', 'Task updated.');
        } else {
            $stmt = $db->prepare('INSERT INTO tasks (title, description, priority, status, due_date) VALUES (?,?,?,?,?)');
            $stmt->execute([$title, $desc, $priority, $status, $due]);
            flash('ok', 'Task added.');
        }
    } elseif ($action === 'toggle' && $id) {
        $db->prepare("UPDATE tasks SET status = CASE WHEN status='done' THEN 'todo' ELSE 'done' END WHERE id=?")->execute([$id]);
    } elseif ($action === 'delete' && $id) {
        $db->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]);
        flash('ok', 'Task deleted.');
    } elseif ($action === 'clear_done') {
        $n = $db->exec("DELETE FROM tasks WHERE status='done'");
        flash('ok', $n . ' completed ' . ($n === 1 ? 'task' : 'tasks') . ' cleared.');
    }

    header('Location: ' . viewUrl());
    exit;
}

/* ---------- Load data (GET) ---------- */
$today = date('Y-m-d');

$where = []; $params = [];
if ($filter === 'overdue') {
    $where[] = "status != 'done' AND due_date IS NOT NULL AND due_date < ?";
    $params[] = $today;
} elseif ($filter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $filter;
}
if ($search !== '') {
    $where[] = '(title LIKE ? OR description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$orderBy = [
    'due'      => 'due_date IS NULL, due_date ASC, id DESC',
    'priority' => "CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, due_date IS NULL, due_date ASC",
    'newest'   => 'id DESC',
][$sort];

$sql = 'SELECT * FROM tasks' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY status = 'done', " . $orderBy;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$counts = $db->query("SELECT
    COUNT(*) AS total,
    SUM(status='todo') AS todo,
    SUM(status='doing') AS doing,
    SUM(status='done') AS done
    FROM tasks")->fetch();
$stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE status != 'done' AND due_date IS NOT NULL AND due_date < ?");
$stmt->execute([$today]);
$counts['overdue'] = (int)$stmt->fetchColumn();
foreach ($counts as $k => $v) $counts[$k] = (int)$v;
$pct = $counts['total'] ? round($counts['done'] / $counts['total'] * 100) : 0;

/* Edit mode */
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}
$form = $editing ?: ['id' => 0, 'title' => '', 'description' => '', 'priority' => 'medium', 'status' => 'todo', 'due_date' => ''];
if (!empty($_SESSION['old'])) {
    $o = $_SESSION['old'];
    $form['title'] = $o['title']; $form['description'] = $o['desc'];
    $form['priority'] = $o['priority']; $form['status'] = $o['status']; $form['due_date'] = $o['due'];
    unset($_SESSION['old']);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function dueLabel(?string $date, string $status, string $today): array {
    if (!$date) return ['No due date', ''];
    $diff = (int)((strtotime($date) - strtotime($today)) / 86400);
    $nice = date('M j', strtotime($date));
    if ($status === 'done') return [$nice, ''];
    if ($diff < 0)  return ['Overdue · ' . $nice, 'overdue'];
    if ($diff === 0) return ['Due today', 'soon'];
    if ($diff === 1) return ['Due tomorrow', 'soon'];
    return ['Due ' . $nice, ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Task Manager</title>
<style>
:root {
    --bg: #eef1f4;
    --panel: #ffffff;
    --ink: #17212b;
    --muted: #5d6b7a;
    --line: #d9dfe6;
    --accent: #0b6b68;
    --accent-ink: #ffffff;
    --high: #c23b22;
    --medium: #d29a1a;
    --low: #4a7fb5;
    --danger: #b3261e;
    --radius: 10px;
    --font: "Segoe UI", system-ui, -apple-system, "Helvetica Neue", Arial, sans-serif;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #11171d; --panel: #19212a; --ink: #e8edf2; --muted: #93a1b0;
        --line: #2a3541; --accent: #3fb8b2; --accent-ink: #08201f;
        --high: #ef6a53; --medium: #e5b53f; --low: #6fa6dc; --danger: #f28b82;
    }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: var(--font); font-size: 16px; line-height: 1.5; }
a { color: inherit; }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.layout { max-width: 1080px; margin: 0 auto; padding: 32px 20px 64px; display: grid; grid-template-columns: 230px 1fr; gap: 32px; }
@media (max-width: 760px) { .layout { grid-template-columns: 1fr; gap: 20px; padding-top: 20px; } }

/* Sidebar */
.side h1 { font-size: 1.5rem; margin: 0 0 4px; letter-spacing: -0.01em; }
.side .sub { color: var(--muted); margin: 0 0 20px; font-size: .9rem; }
.progress { margin-bottom: 24px; }
.progress .bar { height: 8px; background: var(--line); border-radius: 99px; overflow: hidden; }
.progress .bar span { display: block; height: 100%; background: var(--accent); width: <?= $pct ?>%; }
.progress p { margin: 6px 0 0; font-size: .85rem; color: var(--muted); }
.nav { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
@media (max-width: 760px) { .nav { grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); } }
.nav a { display: flex; justify-content: space-between; padding: 8px 12px; border-radius: 8px; text-decoration: none; color: var(--muted); }
.nav a:hover { background: var(--panel); color: var(--ink); }
.nav a.active { background: var(--panel); color: var(--ink); font-weight: 600; box-shadow: inset 3px 0 0 var(--accent); }
.nav .n { font-variant-numeric: tabular-nums; }
.nav .n.alert { color: var(--danger); font-weight: 600; }

/* Panels */
.panel { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); }
.form-panel { padding: 18px 20px 20px; margin-bottom: 20px; }
.form-panel h2 { font-size: 1.05rem; margin: 0 0 12px; }
.grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
@media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
label { display: block; font-size: .85rem; color: var(--muted); margin-bottom: 4px; }
input[type=text], input[type=date], input[type=search], select, textarea {
    width: 100%; font: inherit; color: var(--ink); background: var(--bg);
    border: 1px solid var(--line); border-radius: 8px; padding: 9px 11px;
}
textarea { resize: vertical; min-height: 64px; }
.field { margin-bottom: 12px; }
.actions { display: flex; gap: 10px; align-items: center; margin-top: 4px; }
.btn { font: inherit; cursor: pointer; border: 1px solid var(--line); background: var(--panel); color: var(--ink); padding: 9px 16px; border-radius: 8px; text-decoration: none; display: inline-block; }
.btn:hover { border-color: var(--muted); }
.btn.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 600; }
.btn.primary:hover { filter: brightness(1.08); }
.btn.small { padding: 4px 10px; font-size: .85rem; }
.btn.danger:hover { border-color: var(--danger); color: var(--danger); }

.flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: .95rem; }
.flash.ok { background: color-mix(in srgb, var(--accent) 14%, var(--panel)); border: 1px solid var(--accent); }
.flash.error { background: color-mix(in srgb, var(--danger) 12%, var(--panel)); border: 1px solid var(--danger); }

/* Toolbar */
.toolbar { display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
.toolbar input[type=search] { flex: 1 1 200px; background: var(--panel); }
.toolbar select { width: auto; background: var(--panel); }

/* Task list */
.tasks { list-style: none; margin: 0; padding: 0; }
.task { display: grid; grid-template-columns: auto 1fr auto; gap: 14px; align-items: start; padding: 14px 16px 14px 14px; border-bottom: 1px solid var(--line); border-left: 4px solid var(--medium); }
.task:last-child { border-bottom: 0; }
.task.p-high { border-left-color: var(--high); }
.task.p-medium { border-left-color: var(--medium); }
.task.p-low { border-left-color: var(--low); }
.task.is-done { border-left-color: var(--line); }
.task.is-done .title { text-decoration: line-through; color: var(--muted); }
.tick { all: unset; cursor: pointer; width: 22px; height: 22px; border: 2px solid var(--muted); border-radius: 50%; margin-top: 2px; display: grid; place-items: center; }
.tick:hover { border-color: var(--accent); }
.tick:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.is-done .tick { background: var(--accent); border-color: var(--accent); }
.is-done .tick::after { content: ""; width: 5px; height: 10px; border: solid var(--accent-ink); border-width: 0 2px 2px 0; transform: rotate(45deg) translate(-1px, -1px); }
.title { font-weight: 600; margin: 0; word-break: break-word; }
.desc { margin: 2px 0 0; color: var(--muted); font-size: .92rem; white-space: pre-line; word-break: break-word; }
.meta { display: flex; gap: 8px 14px; flex-wrap: wrap; margin-top: 6px; font-size: .82rem; color: var(--muted); }
.meta .overdue { color: var(--danger); font-weight: 600; }
.meta .soon { color: var(--medium); font-weight: 600; }
.pill { border: 1px solid var(--line); border-radius: 99px; padding: 0 8px; }
.pill.doing { border-color: var(--accent); color: var(--accent); }
.row-actions { display: flex; gap: 6px; }
@media (max-width: 560px) { .task { grid-template-columns: auto 1fr; } .row-actions { grid-column: 2; } }
.empty { padding: 40px 20px; text-align: center; color: var(--muted); }
.footer-row { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; color: var(--muted); font-size: .88rem; }
</style>
</head>
<body>
<div class="layout">

    <aside class="side">
        <h1>Task Manager</h1>
        <p class="sub"><?= date('l, F j') ?></p>

        <div class="progress">
            <div class="bar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span></span></div>
            <p><?= $counts['done'] ?> of <?= $counts['total'] ?> done</p>
        </div>

        <ul class="nav">
            <?php
            $navItems = [
                'all' => ['All tasks', $counts['total']],
                'todo' => ['To do', $counts['todo']],
                'doing' => ['In progress', $counts['doing']],
                'done' => ['Done', $counts['done']],
                'overdue' => ['Overdue', $counts['overdue']],
            ];
            foreach ($navItems as $key => [$label, $n]): ?>
                <li>
                    <a href="<?= h(viewUrl(['filter' => $key])) ?>" class="<?= $filter === $key ? 'active' : '' ?>">
                        <span><?= h($label) ?></span>
                        <span class="n <?= $key === 'overdue' && $n ? 'alert' : '' ?>"><?= $n ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <main>
        <?php if ($flash): ?>
            <div class="flash <?= $flash[0] === 'error' ? 'error' : 'ok' ?>" role="status"><?= h($flash[1]) ?></div>
        <?php endif; ?>

        <section class="panel form-panel">
            <h2><?= $editing ? 'Edit task' : 'Add a task' ?></h2>
            <form method="post" action="<?= h(viewUrl()) ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

                <div class="field">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" maxlength="120" required value="<?= h($form['title']) ?>" placeholder="What needs to get done?" <?= $editing ? 'autofocus' : '' ?>>
                </div>
                <div class="field">
                    <label for="description">Notes (optional)</label>
                    <textarea id="description" name="description"><?= h($form['description']) ?></textarea>
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="priority">Priority</label>
                        <select id="priority" name="priority">
                            <?php foreach (PRIORITIES as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $form['priority'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (STATUSES as $v => $l): ?>
                                <option value="<?= $v ?>" <?= $form['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="due_date">Due date</label>
                        <input type="date" id="due_date" name="due_date" value="<?= h($form['due_date']) ?>">
                    </div>
                </div>
                <div class="actions">
                    <button class="btn primary" type="submit"><?= $editing ? 'Save changes' : 'Add task' ?></button>
                    <?php if ($editing): ?><a class="btn" href="<?= h(viewUrl()) ?>">Cancel</a><?php endif; ?>
                </div>
            </form>
        </section>

        <form class="toolbar" method="get" action="index.php">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= h($filter) ?>"><?php endif; ?>
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search tasks" aria-label="Search tasks">
            <select name="sort" aria-label="Sort tasks" onchange="this.form.submit()">
                <option value="due" <?= $sort === 'due' ? 'selected' : '' ?>>Sort: due date</option>
                <option value="priority" <?= $sort === 'priority' ? 'selected' : '' ?>>Sort: priority</option>
                <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Sort: newest</option>
            </select>
            <button class="btn" type="submit">Search</button>
        </form>

        <section class="panel">
            <?php if (!$tasks): ?>
                <div class="empty">
                    <?= ($search !== '' || $filter !== 'all') ? 'No tasks match this view. Try a different filter or search.' : 'No tasks yet. Add your first one above.' ?>
                </div>
            <?php else: ?>
                <ul class="tasks">
                <?php foreach ($tasks as $t):
                    [$dueText, $dueClass] = dueLabel($t['due_date'], $t['status'], $today); ?>
                    <li class="task p-<?= h($t['priority']) ?> <?= $t['status'] === 'done' ? 'is-done' : '' ?>">
                        <form method="post" action="<?= h(viewUrl()) ?>">
                            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button class="tick" type="submit" aria-label="<?= $t['status'] === 'done' ? 'Mark as not done' : 'Mark as done' ?>: <?= h($t['title']) ?>"></button>
                        </form>

                        <div>
                            <p class="title"><?= h($t['title']) ?></p>
                            <?php if ($t['description'] !== ''): ?><p class="desc"><?= h($t['description']) ?></p><?php endif; ?>
                            <div class="meta">
                                <span class="<?= $dueClass ?>"><?= h($dueText) ?></span>
                                <span><?= h(PRIORITIES[$t['priority']] ?? 'Medium') ?> priority</span>
                                <?php if ($t['status'] === 'doing'): ?><span class="pill doing">In progress</span><?php endif; ?>
                            </div>
                        </div>

                        <div class="row-actions">
                            <a class="btn small" href="<?= h(viewUrl() . (strpos(viewUrl(), '?') !== false ? '&' : '?') . 'edit=' . (int)$t['id']) ?>">Edit</a>
                            <form method="post" action="<?= h(viewUrl()) ?>" onsubmit="return confirm('Delete this task?');">
                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button class="btn small danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($counts['done'] > 0): ?>
            <div class="footer-row">
                <span><?= count($tasks) ?> shown</span>
                <form method="post" action="<?= h(viewUrl()) ?>" onsubmit="return confirm('Delete all completed tasks?');">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="action" value="clear_done">
                    <button class="btn small" type="submit">Clear completed</button>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
