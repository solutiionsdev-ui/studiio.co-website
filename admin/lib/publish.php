<?php
/**
 * STUDIIO.CO admin — pending changes and publishing.
 *
 * Local backend: saves land in the repo folder as uncommitted changes (yours and
 * Claude Code's alike). Publish commits the ones you pick and pushes; Hostinger
 * deploys main. GitHub backend: every save is already a commit, so this lists
 * recent commits instead.
 */
declare(strict_types=1);

require_once __DIR__ . '/content.php';

function git(array $args, bool $allowFail = false): string
{
    $cfg = config();
    $env = getenv();
    $env['GIT_TERMINAL_PROMPT'] = '0';   // never hang waiting for a password
    $env['GIT_LITERAL_PATHSPECS'] = '1'; // file names are names, never patterns
    $root = (string) $cfg['site_root'];
    [$code, $out, $err] = run_cmd(array_merge([(string) $cfg['git']['bin']], $args), $root, null, $env);
    if ($code !== 0 && !$allowFail) {
        throw new ApiError('git ' . $args[0] . ' failed: ' . trim($err ?: $out), 500);
    }
    return $out;
}

/** What kind of change a path is, for grouping in the UI. */
function change_kind(string $path): string
{
    return match (true) {
        str_starts_with($path, 'content/') => 'content',
        str_starts_with($path, 'img/')     => 'image',
        default                            => 'code',
    };
}

/** Uncommitted changes in the repo folder: [['path', 'status', 'kind']]. */
function pending_changes(): array
{
    $raw = git(['status', '--porcelain=v1', '-z', '--untracked-files=all']);
    $items = [];
    $parts = explode("\0", rtrim($raw, "\0"));
    for ($i = 0; $i < count($parts); $i++) {
        $entry = $parts[$i];
        if (strlen($entry) < 4) {
            continue;
        }
        $xy = substr($entry, 0, 2);
        $path = substr($entry, 3);
        if ($xy[0] === 'R' || $xy[0] === 'C') {
            $i++; // renames carry the old path next
        }
        if (preg_match('#^(\.private|\.claude|\.data)/#', $path)) {
            continue;
        }
        $status = match (true) {
            $xy === '??'          => 'added',
            str_contains($xy, 'D') => 'deleted',
            str_contains($xy, 'A') => 'added',
            default               => 'modified',
        };
        $items[] = ['path' => $path, 'status' => $status, 'kind' => change_kind($path)];
    }
    return $items;
}

function changes_summary(): array
{
    $cfg = config();
    if ($cfg['content_backend'] === 'github') {
        [$status, $commits] = github_api('GET', gh_repo_path('/commits') . '?sha=' . rawurlencode((string) $cfg['github']['branch']) . '&per_page=15');
        $list = [];
        foreach (($status === 200 && is_array($commits)) ? $commits : [] as $c) {
            $list[] = [
                'sha'     => substr((string) $c['sha'], 0, 7),
                'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"),
                'author'  => (string) ($c['commit']['author']['name'] ?? ''),
                'date'    => (string) ($c['commit']['author']['date'] ?? ''),
                'url'     => (string) ($c['html_url'] ?? ''),
            ];
        }
        return ['backend' => 'github', 'branch' => $cfg['github']['branch'], 'commits' => $list];
    }
    $branch = trim(git(['rev-parse', '--abbrev-ref', 'HEAD']));
    $ahead = trim(git(['rev-list', '--count', '@{u}..HEAD'], true));
    return [
        'backend' => 'local',
        'branch'  => $branch,
        'liveBranch' => $cfg['github']['branch'] ?: 'main',
        'unpushed' => ctype_digit($ahead) ? (int) $ahead : null,
        'items'   => pending_changes(),
    ];
}

/** Find a path in the pending list (only those can be diffed, published or discarded). */
function pending_item(string $path): array
{
    foreach (pending_changes() as $item) {
        if ($item['path'] === $path) {
            return $item;
        }
    }
    throw new ApiError("$path has no pending changes.", 404);
}

function change_diff(string $path): string
{
    $item = pending_item($path);
    if ($item['kind'] === 'image') {
        return '';
    }
    if ($item['status'] === 'added') {
        $text = (string) @file_get_contents(config()['site_root'] . '/' . $path);
        return "new file\n" . implode("\n", array_map(static fn($l) => '+' . $l, explode("\n", mb_substr($text, 0, 100000))));
    }
    return git(['diff', '--no-color', '--no-ext-diff', 'HEAD', '--', $path]);
}

/** Commit the chosen paths and push. Returns a short log for the UI. */
function publish_changes(array $paths, string $message): array
{
    if (config()['content_backend'] !== 'local') {
        throw new ApiError('With the GitHub backend every save is already published.');
    }
    $paths = array_values(array_unique(array_map('strval', $paths)));
    if (!$paths) {
        throw new ApiError('Pick at least one change to publish.');
    }
    foreach ($paths as $p) {
        pending_item($p);
    }
    $message = trim($message) !== '' ? mb_substr(trim($message), 0, 200) : 'Update site content';
    $g = config()['git'];
    $who = ['-c', 'user.name=' . $g['author_name'], '-c', 'user.email=' . $g['author_email']];
    git(array_merge(['add', '-A', '--'], $paths));
    git(array_merge($who, ['commit', '-m', $message, '--'], $paths));
    $sha = trim(git(['rev-parse', '--short', 'HEAD']));
    $log = ["Committed $sha: $message"];
    $pushed = false;
    if (!empty($g['push'])) {
        $branch = trim(git(['rev-parse', '--abbrev-ref', 'HEAD']));
        $env = getenv();
        $env['GIT_TERMINAL_PROMPT'] = '0';
        [$code, $out, $err] = run_cmd([(string) $g['bin'], 'push', 'origin', 'HEAD'], (string) config()['site_root'], null, $env);
        $pushed = $code === 0;
        $log[] = $pushed ? "Pushed to origin/$branch." : 'Push failed: ' . trim($err ?: $out);
        if ($pushed && $branch === (config()['github']['branch'] ?: 'main')) {
            $log[] = 'Hostinger will deploy it in about a minute.';
        } elseif ($pushed) {
            $log[] = "Note: the live site deploys from " . (config()['github']['branch'] ?: 'main') . ", and this folder is on $branch.";
        }
    }
    return ['sha' => $sha, 'pushed' => $pushed, 'log' => $log];
}

/** Throw away one pending change (restores the last published version, or removes a new file). */
function discard_change(string $path): void
{
    $item = pending_item($path);
    $inHead = trim(git(['ls-tree', '--name-only', 'HEAD', '--', $path], true)) !== '';
    if ($inHead) {
        git(['restore', '--source=HEAD', '--staged', '--worktree', '--', $path]);
        return;
    }
    // A file that isn't in the last commit: unstage it (if a failed publish staged it) and delete it.
    if ($item['kind'] === 'code') {
        throw new ApiError('New code files are not deleted from here. Remove it in Claude Code or Finder.');
    }
    git(['rm', '--cached', '--ignore-unmatch', '--quiet', '--', $path]);
    $root = realpath((string) config()['site_root']);
    $abs = $root . '/' . $path;
    $dir = realpath(dirname($abs));
    if ($root === false || $dir === false || !str_starts_with($dir . '/', $root . '/') || str_contains($path, '..')) {
        throw new ApiError("Refusing to delete $path.", 400);
    }
    if (is_file($abs) && !@unlink($abs)) {
        throw new ApiError("Could not delete $path.", 500);
    }
}
