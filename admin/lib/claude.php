<?php
/**
 * STUDIIO.CO admin — "Ask Claude": send a task to Claude Code.
 *
 * runner 'local'  : runs Claude Code headless (`claude -p`) in the repo folder on this Mac.
 *                   Edits appear as pending changes; publish or discard them from the panel.
 * runner 'github' : starts the "Ask Claude" GitHub Actions workflow (.github/workflows/ask-claude.yml),
 *                   which runs Claude Code in the cloud and opens a pull request. Publish = merge it.
 *
 * Either way Claude Code reads CLAUDE.md and edits the same content/*.json files the panel edits.
 */
declare(strict_types=1);

require_once __DIR__ . '/publish.php';

const CLAUDE_TASK_PROMPT = <<<'TXT'
You are working on the STUDIIO.CO website through the "Ask Claude" box in its admin panel. The person asking is the site owner.
Follow CLAUDE.md. Site content lives in content/*.json; prefer changing those files over code.
Keep English and Arabic in step: when you add or change English text, write the Arabic too, in the same tone as the existing Arabic.
Never invent clients, results, numbers or quotes. Use only images that already exist in img/.
Keep every JSON file valid. You cannot run commands, so read files back to check your edits.
When finished, reply with a short summary of what you changed (file and field). If the request is unclear or needs something you don't have (photos, facts), change nothing and say what you need.
TXT;

function claude_runner(): string
{
    $runner = (string) (config()['claude']['runner'] ?? 'off');
    return in_array($runner, ['local', 'github'], true) ? $runner : 'off';
}

function task_dir(string $id): string
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id)) {
        throw new ApiError('Unknown task.', 404);
    }
    return data_path("tasks/$id/");
}

function task_save(array $task): void
{
    write_atomic(task_dir($task['id']) . 'task.json', encode_json($task));
}

function task_load(string $id): array
{
    $task = read_json_file(task_dir($id) . 'task.json');
    if (!is_array($task)) {
        throw new ApiError('Unknown task.', 404);
    }
    return $task;
}

/** Recent tasks, newest first (status refreshed for unfinished ones). */
function tasks_list(int $limit = 20): array
{
    $dirs = glob(data_path('tasks/') . '*/task.json') ?: [];
    rsort($dirs);
    $out = [];
    foreach (array_slice($dirs, 0, $limit) as $file) {
        $task = read_json_file($file);
        if (is_array($task)) {
            $out[] = in_array($task['status'], ['running', 'queued'], true) ? task_refresh($task) : $task;
        }
    }
    return $out;
}

function task_create(string $prompt): array
{
    $runner = claude_runner();
    if ($runner === 'off') {
        throw new ApiError('Ask Claude is turned off. Set claude.runner to "local" or "github" in config.php.');
    }
    $prompt = trim($prompt);
    if ($prompt === '' || mb_strlen($prompt) > 4000) {
        throw new ApiError('Describe the change in 1 to 4000 characters.');
    }
    $running = array_filter(tasks_list(10), static fn($t) => in_array($t['status'], ['running', 'queued'], true));
    if (count($running) >= 2) {
        throw new ApiError('Two tasks are already running. Wait for one to finish.', 429);
    }
    $task = [
        'id'          => gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)),
        'prompt'      => $prompt,
        'runner'      => $runner,
        'status'      => 'queued',
        'created_at'  => gmdate('c'),
        'finished_at' => null,
        'result'      => '',
        'files'       => [],
        'steps'       => [],
        'cost_usd'    => null,
        'error'       => '',
    ];
    task_save($task);
    return $runner === 'local' ? task_start_local($task) : task_start_github($task);
}

/* --------------------------------------------------------------------------
   Local runner (Claude Code on this Mac)
   -------------------------------------------------------------------------- */

function task_start_local(array $task): array
{
    $cfg = config();
    $c = $cfg['claude'];
    $dir = task_dir($task['id']);
    file_put_contents($dir . 'prompt.txt', $task['prompt']);
    $cmd = [
        (string) $c['bin'], '-p',
        '--model', (string) $c['model'],
        '--output-format', 'stream-json', '--verbose',
        '--restricted',                       // no shell or web tools; file tools stay inside the repo
        '--strict-mcp-config',                // no MCP servers
        '--permission-mode', 'acceptEdits',
        '--allowedTools', 'Read,Edit,Write,Glob,Grep',
        '--max-budget-usd', (string) ($c['max_budget_usd'] ?? 3),
        '--no-session-persistence',
        '--append-system-prompt', CLAUDE_TASK_PROMPT,
    ];
    $env = claude_child_env();
    $env['TASK_DIR'] = $dir;
    // Detach: the prompt goes in on stdin, events stream to out.jsonl, the exit code lands in "exit".
    $script = '( nohup "$@" < "$TASK_DIR/prompt.txt" > "$TASK_DIR/out.jsonl" 2> "$TASK_DIR/err.log"; echo $? > "$TASK_DIR/exit" ) > /dev/null 2>&1 & echo $!';
    [$code, $out] = run_cmd(array_merge(['/bin/sh', '-c', $script, 'sh'], $cmd), (string) $cfg['site_root'], null, $env);
    if ($code !== 0) {
        $task['status'] = 'failed';
        $task['error'] = 'Could not start Claude Code.';
    } else {
        $task['status'] = 'running';
        $task['pid'] = (int) trim($out);
    }
    task_save($task);
    return $task;
}

/**
 * Environment for the headless Claude Code run. Drops variables a parent Claude Code
 * session or the Claude desktop app sets (their API proxy and auth hooks only work for
 * that session), so the CLI uses its own login: `claude auth login`, or claude.api_key in config.php.
 */
function claude_child_env(): array
{
    $env = [];
    foreach (getenv() as $key => $value) {
        if (preg_match('/^(CLAUDECODE$|CLAUDE_|ANTHROPIC_BASE_URL$|ANTHROPIC_AUTH_TOKEN$)/', (string) $key)) {
            continue;
        }
        $env[$key] = $value;
    }
    $home = $env['HOME'] ?? (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
    $env['HOME'] = $home;
    $env['PATH'] = ($env['PATH'] ?? '/usr/bin:/bin') . ':' . $home . '/.local/bin:/opt/homebrew/bin:/usr/local/bin';
    $key = (string) (config()['claude']['api_key'] ?? '');
    if ($key !== '') {
        $env['ANTHROPIC_API_KEY'] = $key;
    }
    return $env;
}

/** Turn Claude Code's sign-in errors into something actionable in the panel. */
function claude_error_hint(string $error): string
{
    if (preg_match('/log ?in|authenticat|oauth|api key|credential/i', $error)) {
        return $error . "\n\nClaude Code on this Mac isn't signed in. Open Terminal, run `claude auth login`, sign in with your Claude account, then send the task again. (Or put an Anthropic API key in claude.api_key in .private/config.php.)";
    }
    return $error;
}

/** Read Claude Code's event stream: files it edited, a step log, and the final result. */
function parse_claude_stream(string $file, string $root): array
{
    $files = [];
    $steps = [];
    $result = null;
    $fh = @fopen($file, 'r');
    while ($fh && ($line = fgets($fh)) !== false) {
        $ev = json_decode($line, true);
        if (!is_array($ev)) {
            continue;
        }
        if (($ev['type'] ?? '') === 'assistant') {
            foreach ((array) ($ev['message']['content'] ?? []) as $part) {
                if (($part['type'] ?? '') !== 'tool_use') {
                    continue;
                }
                $name = (string) ($part['name'] ?? '');
                $target = (string) ($part['input']['file_path'] ?? $part['input']['pattern'] ?? $part['input']['path'] ?? '');
                $rel = str_starts_with($target, $root . '/') ? substr($target, strlen($root) + 1) : $target;
                $steps[] = trim("$name $rel");
                if (in_array($name, ['Edit', 'Write', 'MultiEdit'], true) && $rel !== '') {
                    $files[$rel] = true;
                }
            }
        } elseif (($ev['type'] ?? '') === 'result') {
            $result = $ev;
        }
    }
    if ($fh) {
        fclose($fh);
    }
    return [array_keys($files), array_slice($steps, -40), $result];
}

function task_refresh(array $task): array
{
    if (!in_array($task['status'], ['running', 'queued'], true)) {
        return $task;
    }
    return $task['runner'] === 'github' ? task_refresh_github($task) : task_refresh_local($task);
}

function task_refresh_local(array $task): array
{
    $dir = task_dir($task['id']);
    $root = rtrim((string) config()['site_root'], '/');
    [$files, $steps, $result] = parse_claude_stream($dir . 'out.jsonl', $root);
    $task['files'] = $files;
    $task['steps'] = $steps;
    $finished = is_file($dir . 'exit');
    $alive = !empty($task['pid']) && function_exists('posix_kill') && @posix_kill((int) $task['pid'], 0);
    if (!$finished && ($alive || !function_exists('posix_kill'))) {
        task_save($task);
        return $task;
    }
    $task['finished_at'] = gmdate('c');
    if ($result !== null) {
        $task['result'] = (string) ($result['result'] ?? '');
        $task['cost_usd'] = isset($result['total_cost_usd']) ? round((float) $result['total_cost_usd'], 4) : null;
        $failed = !empty($result['is_error']);
        $task['status'] = $failed ? 'failed' : ($files ? 'review' : 'done');
        if ($failed) {
            $task['error'] = claude_error_hint($task['result'] ?: (string) ($result['subtype'] ?? 'Claude Code stopped with an error.'));
            $task['result'] = '';
        }
    } else {
        $task['status'] = 'failed';
        $task['error'] = claude_error_hint(trim(mb_substr((string) @file_get_contents($dir . 'err.log') . "\n" . (string) @file_get_contents($dir . 'out.jsonl'), 0, 2000)) ?: 'Claude Code exited without a result.');
    }
    task_save($task);
    return $task;
}

/* --------------------------------------------------------------------------
   GitHub runner (Claude Code in GitHub Actions → pull request)
   -------------------------------------------------------------------------- */

function task_branch(array $task): string
{
    return 'claude/task-' . $task['id'];
}

function task_start_github(array $task): array
{
    $c = config();
    $workflow = rawurlencode((string) ($c['claude']['workflow'] ?: 'ask-claude.yml'));
    [$status, $body] = github_api('POST', gh_repo_path("/actions/workflows/$workflow/dispatches"), [
        'ref'    => (string) ($c['github']['branch'] ?: 'main'),
        'inputs' => ['prompt' => $task['prompt'], 'task_id' => $task['id']],
    ]);
    if ($status !== 204 && $status !== 200) {
        $task['status'] = 'failed';
        $task['error'] = 'GitHub did not start the workflow (HTTP ' . $status . '): ' . ($body['message'] ?? '') . ' Is .github/workflows/ask-claude.yml on the main branch?';
    } else {
        $task['status'] = 'running';
    }
    task_save($task);
    return $task;
}

function task_refresh_github(array $task): array
{
    $c = config();
    $workflow = rawurlencode((string) ($c['claude']['workflow'] ?: 'ask-claude.yml'));
    [$status, $body] = github_api('GET', gh_repo_path("/actions/workflows/$workflow/runs") . '?event=workflow_dispatch&per_page=30');
    $run = null;
    foreach (($status === 200 ? ($body['workflow_runs'] ?? []) : []) as $r) {
        if (str_contains((string) ($r['display_title'] ?? ''), $task['id'])) {
            $run = $r;
            break;
        }
    }
    if ($run === null) {
        // Not listed yet (GitHub takes a few seconds), or it never started.
        if (time() - strtotime((string) $task['created_at']) > 600) {
            $task['status'] = 'failed';
            $task['error'] = 'The workflow run never appeared on GitHub.';
            task_save($task);
        }
        return $task;
    }
    $task['run_url'] = (string) ($run['html_url'] ?? '');
    $task['steps'] = ['GitHub Actions: ' . ($run['status'] ?? '') . ($run['conclusion'] ? ' · ' . $run['conclusion'] : '')];
    if (($run['status'] ?? '') !== 'completed') {
        task_save($task);
        return $task;
    }
    $task['finished_at'] = gmdate('c');
    if (($run['conclusion'] ?? '') !== 'success') {
        $task['status'] = 'failed';
        $task['error'] = 'The workflow finished with "' . ($run['conclusion'] ?? 'unknown') . '". Open the run on GitHub for the log.';
        task_save($task);
        return $task;
    }
    $pr = task_pull_request($task);
    if ($pr === null) {
        $task['status'] = 'done';
        $task['result'] = 'Claude finished without changing any files.';
    } else {
        $task['status'] = 'review';
        $task['pr'] = ['number' => $pr['number'], 'url' => $pr['html_url']];
        $task['result'] = trim((string) ($pr['body'] ?? '')) ?: 'Pull request opened.';
        [$fs, $files] = github_api('GET', gh_repo_path('/pulls/' . (int) $pr['number'] . '/files') . '?per_page=100');
        $task['files'] = $fs === 200 ? array_map(static fn($f) => (string) $f['filename'], (array) $files) : [];
    }
    task_save($task);
    return $task;
}

function task_pull_request(array $task): ?array
{
    $owner = explode('/', (string) config()['github']['repo'])[0];
    [$status, $pulls] = github_api('GET', gh_repo_path('/pulls') . '?state=all&head=' . rawurlencode($owner . ':' . task_branch($task)));
    return ($status === 200 && !empty($pulls[0])) ? $pulls[0] : null;
}

/** Diffs of the files a finished task changed. */
function task_diff(array $task): array
{
    $out = [];
    if ($task['runner'] === 'github' && !empty($task['pr']['number'])) {
        [$status, $files] = github_api('GET', gh_repo_path('/pulls/' . (int) $task['pr']['number'] . '/files') . '?per_page=100');
        foreach ($status === 200 ? (array) $files : [] as $f) {
            $out[] = ['path' => (string) $f['filename'], 'diff' => (string) ($f['patch'] ?? '')];
        }
        return $out;
    }
    $pending = array_column(pending_changes(), 'path');
    foreach ($task['files'] as $path) {
        $out[] = ['path' => $path, 'diff' => in_array($path, $pending, true) ? change_diff($path) : '(already published or discarded)'];
    }
    return $out;
}

/** Accept a task's changes: publish (local) or merge the pull request (GitHub). */
function task_publish(string $id): array
{
    $task = task_load($id);
    if ($task['status'] !== 'review') {
        throw new ApiError('Only finished tasks with changes can be published.');
    }
    if ($task['runner'] === 'github') {
        [$status, $body] = github_api('PUT', gh_repo_path('/pulls/' . (int) $task['pr']['number'] . '/merge'), [
            'merge_method' => 'squash',
            'commit_title' => 'Ask Claude: ' . mb_substr($task['prompt'], 0, 60),
        ]);
        if ($status !== 200) {
            throw new ApiError('GitHub could not merge the pull request: ' . ($body['message'] ?? "HTTP $status"), 502);
        }
        github_api('DELETE', gh_repo_path('/git/refs/heads/' . task_branch($task)));
        $log = ['Merged pull request #' . $task['pr']['number'] . '. Hostinger will deploy it in about a minute.'];
    } else {
        $pending = array_column(pending_changes(), 'path');
        $paths = array_values(array_intersect($task['files'], $pending));
        if (!$paths) {
            throw new ApiError('These changes were already published or discarded.');
        }
        $log = publish_changes($paths, 'Ask Claude: ' . mb_substr($task['prompt'], 0, 60))['log'];
    }
    $task['status'] = 'published';
    task_save($task);
    return ['task' => $task, 'log' => $log];
}

function task_discard(string $id): array
{
    $task = task_load($id);
    if ($task['status'] !== 'review') {
        throw new ApiError('Only finished tasks with changes can be discarded.');
    }
    if ($task['runner'] === 'github') {
        github_api('PATCH', gh_repo_path('/pulls/' . (int) $task['pr']['number']), ['state' => 'closed']);
        github_api('DELETE', gh_repo_path('/git/refs/heads/' . task_branch($task)));
    } else {
        $pending = array_column(pending_changes(), 'path');
        foreach (array_intersect($task['files'], $pending) as $path) {
            discard_change($path);
        }
    }
    $task['status'] = 'discarded';
    task_save($task);
    return $task;
}
