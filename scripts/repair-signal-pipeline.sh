#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export GIT_OPTIONAL_LOCKS=0
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES

APP_DIR=/var/www/server.trademinator.com
RUNTIME_USER=apache
CRON_FILE=/etc/cron.d/trademinator
PHP_BIN=/usr/bin/php8.5
COMPOSER_BIN=/usr/local/bin/composer
BASELINE=e11a819fa570fb500955b4c42775c5d2b0fc735d
BACKUP_ROOT=/var/backups/trademinator-signal-repair
RECOVERY_COMMAND=trademinator:recover-signal-queue
PATCH_FILE=
PATCH_SHA256=
BACKUP_DIR=
APPLY=0
MUTATION_STARTED=0

usage() {
    cat <<'HELP'
Usage: bash scripts/repair-signal-pipeline.sh --patch /absolute/tested.patch [--apply]

Check-only is the default. Run from a root shell; the installer invokes application
commands as apache. --apply authorizes the prepared rollout and queue recovery.

Pinned installation:
  Application: /var/www/server.trademinator.com
  Cron:        /etc/cron.d/trademinator
  PHP:         /usr/bin/php8.5
  Composer:    /usr/local/bin/composer
  Git HEAD:    e11a819fa570fb500955b4c42775c5d2b0fc735d

The apply path privately backs up the affected files, .env and cron, pauses this
installation's cron, stops its known Artisan workers/dispatchers, applies the
patch, sets the approved budgets, and runs targeted queue recovery. Unknown
application PHP/Artisan processes must be inspected separately and are not killed.

Before patching begins, a failure restores the original cron. Once patching or
queue mutation starts, a failure leaves cron paused and prints the backup path.
There is no automatic code, environment, cache, or queue rollback.
HELP
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

finish() {
    local status=$?
    trap - EXIT
    if (( status != 0 )) && [[ -n "$BACKUP_DIR" ]]; then
        # Inspect actual files: a signal can arrive immediately after either
        # atomic cron transition, before the next shell statement executes.
        if [[ ! -e "$CRON_FILE" && -f "$BACKUP_DIR/cron.paused" ]]; then
            if (( MUTATION_STARTED )); then
                printf '\nRollout failed after mutation began. Cron remains PAUSED.\nPrivate backup: %s\nInspect the application and recovery logs before resuming cron.\n' "$BACKUP_DIR" >&2
            elif python3 -c 'import os, sys; os.link(sys.argv[1], sys.argv[2]); os.unlink(sys.argv[1])' "$BACKUP_DIR/cron.paused" "$CRON_FILE"; then
                printf '\nNo patch or queue mutation began; the original cron was restored.\nPrivate backup: %s\n' "$BACKUP_DIR" >&2
            else
                printf '\nCould not restore cron without replacing another file. Original: %s/cron.paused\n' "$BACKUP_DIR" >&2
            fi
        elif [[ -f "$CRON_FILE" && -f "$BACKUP_DIR/cron.new" ]] && cmp -s -- "$CRON_FILE" "$BACKUP_DIR/cron.new"; then
            printf '\nThe repair steps completed and updated cron is already active; final reporting was interrupted.\nPrivate backup: %s\n' "$BACKUP_DIR" >&2
        elif [[ -f "$BACKUP_DIR/cron.paused" ]]; then
            printf '\nA live cron file is present; it was not overwritten. Inspect its contents before continuing.\nPrivate backup: %s\n' "$BACKUP_DIR" >&2
        else
            printf '\nThe original cron was not paused. Private backup: %s\n' "$BACKUP_DIR" >&2
        fi
    fi
    exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

while (( $# )); do
    case "$1" in
        --patch)
            (( $# >= 2 )) || fail '--patch requires an absolute file path.'
            PATCH_FILE=$2
            shift 2
            ;;
        --apply)
            APPLY=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *) fail "Unknown argument: $1" ;;
    esac
done

(( EUID == 0 )) || fail 'Run this installer from a root shell; it does not invoke sudo.'
[[ "$PATCH_FILE" = /* && -f "$PATCH_FILE" && ! -L "$PATCH_FILE" ]] || fail '--patch must name an existing absolute regular file, not a symlink.'
for required in python3 git runuser flock sha256sum install cmp mktemp id tee; do
    command -v "$required" >/dev/null || fail "Required command is unavailable: $required"
done
[[ -x "$PHP_BIN" && -f "$COMPOSER_BIN" ]] || fail 'The pinned PHP or Composer executable is unavailable.'
[[ -d "$APP_DIR" && -f "$APP_DIR/artisan" && -f "$APP_DIR/vendor/autoload.php" ]] || fail 'The pinned application or its installed dependencies are unavailable.'
[[ -f "$APP_DIR/.env" && ! -L "$APP_DIR/.env" ]] || fail 'The existing application .env must be a regular file.'
[[ -f "$CRON_FILE" && ! -L "$CRON_FILE" ]] || fail 'The existing cron file must be a regular file.'
id "$RUNTIME_USER" >/dev/null || fail "Runtime user is unavailable: $RUNTIME_USER"
cd -- "$APP_DIR"
PATCH_SHA256=$(sha256sum -- "$PATCH_FILE")
PATCH_SHA256=${PATCH_SHA256%% *}

as_runtime() {
    runuser -u "$RUNTIME_USER" -- "$@"
}

helper() {
    python3 - "$1" "$APP_DIR" "$CRON_FILE" "$RUNTIME_USER" "$PHP_BIN" "$BASELINE" "$PATCH_FILE" "$PATCH_SHA256" "$BACKUP_DIR" <<'PY'
import hashlib
import os
import pathlib
import re
import shlex
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time

mode, app, cron, runtime, php, baseline, patch, patch_hash, backup = sys.argv[1:]
app_path = pathlib.Path(app)
cron_path = pathlib.Path(cron)
backup_path = pathlib.Path(backup) if backup else None
approved = {
    'INTELLIGENCE_MAX_SECONDS': '14400',
    'INTELLIGENCE_JOB_TIMEOUT': '15000',
    'REDIS_QUEUE_RETRY_AFTER': '15300',
    'INTELLIGENCE_SIGNALS_QUEUE': 'signals',
    'INTELLIGENCE_SIGNALS_CONNECTION': 'redis-signals',
    'REDIS_SIGNALS_RETRY_AFTER': '1200',
}
known_commands = {
    'queue:work', 'schedule:run', 'schedule:finish',
    'trademinator:dispatch-market-feeds', 'trademinator:dispatch-market-features',
    'trademinator:dispatch-market-signals', 'trademinator:dispatch-market-intelligence',
    'trademinator:dispatch-lead-lag', 'trademinator:backfill-ohlcv',
    'trademinator:evaluate-candle-period',
}
unrelated_scheduled_commands = {
    'trademinator:collect-market-context', 'trademinator:collect-market-events',
    'trademinator:refresh-market-discovery', 'trademinator:refresh-exchanges',
    'trademinator:prune-access-statistics', 'trademinator:archive-eligible-tickers',
    'trademinator:prune-portable-archives',
}

def abort(message):
    raise RuntimeError(message)

def git(*arguments, input_data=None, allowed=(0,)):
    result = subprocess.run(
        ['runuser', '-u', runtime, '--', 'git', '-c', 'safe.directory=' + app,
         '--literal-pathspecs', '-C', app, *arguments],
        input=input_data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    )
    if result.returncode not in allowed:
        abort('Git check failed: ' + result.stderr.decode(errors='replace').strip())
    return result

def patch_files():
    data = pathlib.Path(patch).read_bytes()
    if hashlib.sha256(data).hexdigest() != patch_hash:
        abort('The patch changed during preflight; start again with the reviewed patch.')
    text = data.decode('utf-8')
    if re.search(r'^(GIT binary patch|Binary files |rename |copy )', text, re.M):
        abort('This installer accepts a text-only patch without renames or copies.')
    if re.search(r'^(?:new file mode|deleted file mode|old mode|new mode) (?!100644$|100755$)', text, re.M):
        abort('The patch contains an unsupported file type or mode.')
    paths = []
    for old, new in re.findall(r'^diff --git a/(\S+) b/(\S+)$', text, re.M):
        if old != new or not re.fullmatch(r'[A-Za-z0-9_.\-/]+', new):
            abort('The patch contains a renamed or unsupported path.')
        parts = pathlib.PurePosixPath(new).parts
        if any(part in ('.', '..', '.git') for part in parts) or new.startswith('/') or new == '.env':
            abort('The patch targets a protected or unsafe path: ' + new)
        for candidate in [app_path / new, *(app_path / new).parents]:
            if candidate == app_path:
                break
            if candidate.is_symlink():
                abort('A patch target traverses a symlink: ' + new)
        if (app_path / new).exists() and not (app_path / new).is_file():
            abort('A patch target is not a regular file: ' + new)
        paths.append(new)
    if not paths or len(paths) != len(set(paths)) or len(paths) != text.count('\ndiff --git ') + int(text.startswith('diff --git ')):
        abort('The patch does not have a supported, unambiguous Git diff header for every file.')
    return paths, data

def revised_cron(source):
    lines = source.splitlines(keepends=True)
    intelligence = []
    for index, line in enumerate(lines):
        stripped = line.strip()
        if not stripped or stripped.startswith('#') or re.match(r'^[A-Za-z_]\w*\s*=', stripped):
            continue
        tokens = shlex.split(stripped)
        if len(tokens) < 8 or tokens[5:8] != [runtime, 'cd', app]:
            abort('Cron contains an unrecognized account/application entry; it cannot be paused as one installation.')
        if '--queue=signals' in tokens or 'redis-signals' in tokens:
            abort('A signals worker already exists in cron; inspect it before applying this one-time repair.')
        if '--queue=intelligence' in tokens:
            if tokens[:5] != ['*'] * 5 or 'queue:work' not in tokens:
                abort('The intelligence cron schedule or command differs from the reviewed installation.')
            if 'storage/framework/trademinator-intelligence-queue.lock' not in tokens or 'memory_limit=512M' not in tokens or '--memory=384' not in tokens:
                abort('The intelligence cron lock or memory settings differ from the reviewed installation.')
            if len(re.findall(r'(?<!\S)--timeout=\d+(?!\S)', line)) != 1:
                abort('The intelligence cron must contain exactly one numeric --timeout option.')
            intelligence.append(index)
    if len(intelligence) != 1:
        abort('Expected exactly one existing intelligence worker cron entry.')
    index = intelligence[0]
    lines[index] = re.sub(r'(?<!\S)--timeout=\d+(?!\S)', '--timeout=15000', lines[index])
    result = ''.join(lines)
    if result and not result.endswith('\n'):
        result += '\n'
    return result + (
        '\n# Independent signal recording worker.\n'
        f'* * * * * {runtime} cd {app} && /usr/bin/flock -n '
        'storage/framework/trademinator-signals-queue.lock '
        f'{php} -d memory_limit=512M artisan queue:work redis-signals --queue=signals '
        '--stop-when-empty --max-time=50 --memory=384 --timeout=900 --tries=2 '
        '>> storage/logs/queue-signals-cron.log 2>&1\n'
    )

def snapshot(pid):
    try:
        root = pathlib.Path('/proc') / str(pid)
        cwd = os.readlink(root / 'cwd')
        arguments = (root / 'cmdline').read_bytes().split(b'\0')[:-1]
        fields = (root / 'stat').read_text().rsplit(')', 1)[1].split()
        if not arguments or fields[0] == 'Z':
            return None
        return (pid, fields[19], cwd, tuple(arguments))
    except (FileNotFoundError, ProcessLookupError):
        return None

def processes():
    known, unknown = [], []
    for item in pathlib.Path('/proc').iterdir():
        if not item.name.isdigit():
            continue
        process = snapshot(int(item.name))
        if process is None:
            continue
        pid, _, cwd, raw = process
        arguments = [value.decode(errors='replace') for value in raw]
        if not re.fullmatch(r'php(?:\d+(?:\.\d+)*)?', os.path.basename(arguments[0])):
            continue
        absolute_artisan = app + '/artisan'
        if cwd != app and absolute_artisan not in arguments:
            continue
        artisan = next((i for i, value in enumerate(arguments) if value in ('artisan', absolute_artisan)), None)
        command = arguments[artisan + 1] if artisan is not None and len(arguments) > artisan + 1 else '(unrecognized PHP command)'
        if cwd != app:
            unknown.append((pid, command + ' from another working directory'))
        elif command in known_commands:
            known.append((process, command))
        elif command not in unrelated_scheduled_commands:
            unknown.append((pid, command))
    if unknown:
        abort('Unknown active application process; inspect it manually: ' + ', '.join(f'PID {pid} {command}' for pid, command in unknown))
    return known

def send_checked(process, signum):
    descriptor = None
    try:
        if hasattr(os, 'pidfd_open') and hasattr(signal, 'pidfd_send_signal'):
            try:
                descriptor = os.pidfd_open(process[0])
            except OSError:
                descriptor = None
        if snapshot(process[0]) != process:
            return False
        if descriptor is not None:
            signal.pidfd_send_signal(descriptor, signum)
        else:
            os.kill(process[0], signum)
        return True
    except ProcessLookupError:
        return False
    finally:
        if descriptor is not None:
            os.close(descriptor)

def preserve_copy(source, destination):
    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    shutil.copy2(source, destination)
    original = source.stat()
    os.chown(destination, original.st_uid, original.st_gid)

def verify_patch():
    if str(app_path.resolve()) != app or git('rev-parse', '--show-toplevel').stdout.decode().strip() != app:
        abort('The application path must be the pinned, non-symlink Git working tree.')
    if git('rev-parse', 'HEAD').stdout.decode().strip() != baseline:
        abort('Git HEAD differs from the reviewed baseline; rebuild/review the patch for this checkout.')
    paths, data = patch_files()
    if (git('diff', '--cached', '--quiet', 'HEAD', '--', *paths, allowed=(0, 1)).returncode
            or git('diff', '--quiet', '--', *paths, allowed=(0, 1)).returncode):
        abort('The patch touches locally modified or staged paths; unrelated changes are allowed, overlapping changes are not.')
    for path in paths:
        if (app_path / path).exists() and git('ls-files', '--error-unmatch', '--', path, allowed=(0, 1)).returncode:
            abort('The patch would overwrite an untracked file: ' + path)
    git('apply', '--check', '-', input_data=data)
    print(f'Baseline and patch verified: {len(paths)} affected paths; unrelated local edits are preserved.')

try:
    if mode == 'preflight':
        verify_patch()
        revised_cron(cron_path.read_text())
        running = processes()
        print(f'Known active workers/dispatchers: {len(running)}. Apply will stop these after pausing cron.')
    elif mode == 'check-patch':
        verify_patch()
    elif mode == 'backup':
        if cron_path.stat().st_dev != backup_path.stat().st_dev:
            abort('Cron and the backup directory must share a filesystem for an atomic pause/restore.')
        paths, data = patch_files()
        for path in [*paths, '.env']:
            source = app_path / path
            if source.exists():
                preserve_copy(source, backup_path / 'files' / path)
        preserve_copy(cron_path, backup_path / 'cron.original')
        (backup_path / 'repair.patch').write_bytes(data)
        (backup_path / 'affected-paths.txt').write_text('\n'.join(paths) + '\n')
        (backup_path / 'new-paths.txt').write_text(''.join(path + '\n' for path in paths if not (app_path / path).exists()))
        (backup_path / 'cron.new').write_text(revised_cron(cron_path.read_text()))
        (backup_path / 'patch.sha256').write_text(patch_hash + '  repair.patch\n')
        print('Private backups and the revised cron are prepared.')
    elif mode == 'quiesce':
        # Cron may already have selected this minute's commands when its file moves.
        # Observe the next minute boundary, repeatedly stopping any late children.
        settle_at = (int(time.time()) // 60 + 1) * 60 + 3
        deadline = time.monotonic() + 90
        terminated = {}
        quiet_since = None
        while True:
            if cron_path.exists():
                abort('The cron file reappeared while workers were being stopped.')
            running = processes()
            now = time.monotonic()
            for process, command in running:
                if process not in terminated:
                    if send_checked(process, signal.SIGTERM):
                        print(f'TERM PID {process[0]} {command}', flush=True)
                    terminated[process] = now
                elif now - terminated[process] >= 15:
                    if send_checked(process, signal.SIGKILL):
                        print(f'KILL PID {process[0]} {command} after the graceful wait', flush=True)
            if running:
                quiet_since = None
            elif quiet_since is None:
                quiet_since = now
            if not running and time.time() >= settle_at and now - quiet_since >= 2:
                print('Workers and dispatchers are stopped; the cron reload boundary has passed.')
                break
            if now >= deadline:
                abort('Application processes did not quiesce within 90 seconds.')
            time.sleep(1)
    elif mode == 'assert-stopped':
        if cron_path.exists() or processes():
            abort('Cron or a known worker/dispatcher resumed before recovery completed.')
    elif mode == 'environment':
        destination = app_path / '.env'
        original = (backup_path / 'files' / '.env').read_bytes()
        if destination.read_bytes() != original:
            abort('The .env file changed after backup; it was not overwritten.')
        found = set()
        lines = []
        for line in original.decode('utf-8').splitlines(keepends=True):
            match = re.match(r'^\s*(?:export\s+)?([A-Za-z_]\w*)\s*=', line)
            key = match.group(1) if match else None
            if key not in approved:
                lines.append(line)
            elif key not in found:
                lines.append(key + '=' + approved[key] + ('\r\n' if line.endswith('\r\n') else '\n'))
                found.add(key)
        text = ''.join(lines)
        if text and not text.endswith('\n'):
            text += '\n'
        text += ''.join(key + '=' + value + '\n' for key, value in approved.items() if key not in found)
        original_stat = destination.stat()
        descriptor, temporary = tempfile.mkstemp(prefix='.env.signal-repair.', dir=app)
        try:
            with os.fdopen(descriptor, 'w') as output:
                output.write(text)
                output.flush()
                os.fsync(output.fileno())
            os.chown(temporary, original_stat.st_uid, original_stat.st_gid)
            os.chmod(temporary, stat.S_IMODE(original_stat.st_mode))
            os.replace(temporary, destination)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
        print('Updated only the six approved intelligence/signal environment keys.')
    else:
        abort('Unknown installer helper operation.')
except (OSError, UnicodeError, ValueError, RuntimeError) as error:
    print('ERROR: ' + str(error), file=sys.stderr)
    sys.exit(1)
PY
}

printf 'Checking the signal repair for %s\n' "$APP_DIR"
as_runtime test -w "$APP_DIR" || fail 'The runtime account cannot update the application working tree.'
as_runtime "$PHP_BIN" -d memory_limit=512M -r 'if (! extension_loaded("pcntl") || ! function_exists("pcntl_alarm")) { fwrite(STDERR, "PCNTL and pcntl_alarm are required for worker timeouts.\n"); exit(1); } echo "PHP ".PHP_VERSION.": PCNTL alarm available.\n";'
as_runtime "$PHP_BIN" -d memory_limit=512M "$COMPOSER_BIN" --no-plugins --version --no-ansi
helper preflight
as_runtime "$PHP_BIN" -d memory_limit=512M -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (config("queue.default") !== "redis") { fwrite(STDERR, "This recovery requires the reviewed Redis queue connection.\n"); exit(1); } echo "Current queue connection: redis.\n";'

if (( ! APPLY )); then
    printf '\nCheck-only passed. No application, environment, cron, cache, or queue changes were made.\nRe-run with --apply from a root shell to perform the prepared repair.\n'
    exit 0
fi

install -d -o root -g root -m 0700 -- "$BACKUP_ROOT"
exec 9>"$BACKUP_ROOT/apply.lock"
flock -n 9 || fail 'Another signal-pipeline installer is already running.'
BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/repair.XXXXXXXX")
helper backup
printf 'Private backup: %s\n' "$BACKUP_DIR"
python3 -c 'import os, sys; os.rename(sys.argv[1], sys.argv[2])' "$CRON_FILE" "$BACKUP_DIR/cron.paused"
printf 'Cron is paused. Stopping known workers and observing the next cron reload boundary.\n'
helper quiesce

# Check again after stopping old processes, using the immutable private patch copy.
PATCH_FILE=$BACKUP_DIR/repair.patch
helper assert-stopped
helper check-patch
MUTATION_STARTED=1
as_runtime git -c safe.directory="$APP_DIR" -C "$APP_DIR" apply - < "$PATCH_FILE"
helper environment
as_runtime "$PHP_BIN" -d memory_limit=512M "$COMPOSER_BIN" install --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-plugins --no-scripts
as_runtime "$PHP_BIN" -d memory_limit=512M artisan config:clear --no-interaction
as_runtime "$PHP_BIN" -d memory_limit=512M artisan config:cache --no-interaction
as_runtime "$PHP_BIN" -d memory_limit=512M -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $values = ["automatic_seconds" => config("intelligence.max_seconds"), "job_timeout" => App\Domain\Intelligence\TrainingRuntime::jobTimeout(), "lock_seconds" => App\Domain\Intelligence\TrainingRuntime::lockSeconds(), "training_retry_after" => config("queue.connections.redis.retry_after"), "signals_queue" => config("intelligence.signals.queue"), "signals_connection" => config("intelligence.signals.connection"), "signals_retry_after" => config("queue.connections.redis-signals.retry_after")]; $expected = ["automatic_seconds" => 14400, "job_timeout" => 15000, "lock_seconds" => 15060, "training_retry_after" => 15300, "signals_queue" => "signals", "signals_connection" => "redis-signals", "signals_retry_after" => 1200]; echo json_encode($values, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n"; if ($values !== $expected) { fwrite(STDERR, "Effective runtime configuration differs from the reviewed values.\n"); exit(1); }'

# The recovery command takes the intelligence/signals cron flocks itself.
# Only the installer's distinct private apply.lock is held here.
helper assert-stopped
printf '\nQueue recovery preview:\n'
as_runtime "$PHP_BIN" -d memory_limit=512M artisan "$RECOVERY_COMMAND" --no-interaction | tee "$BACKUP_DIR/recovery-preview.log"
helper assert-stopped
printf '\nApplying the backed-up queue recovery:\n'
as_runtime "$PHP_BIN" -d memory_limit=512M artisan "$RECOVERY_COMMAND" --apply --no-interaction | tee "$BACKUP_DIR/recovery-apply.log"
helper assert-stopped

[[ ! -e "$CRON_FILE" ]] || fail 'Cron was replaced by another process; it was not overwritten.'
CRON_STAGED=${CRON_FILE%/*}/.trademinator-signal-repair.$$
install -o root -g root -m 0644 -- "$BACKUP_DIR/cron.new" "$CRON_STAGED"
python3 -c 'import os, sys; os.link(sys.argv[1], sys.argv[2]); os.unlink(sys.argv[1])' "$CRON_STAGED" "$CRON_FILE"
printf '\nRepair completed; cron is resumed with separate intelligence and signal workers.\nPrivate backup and recovery summaries: %s\nSignal worker log: %s/storage/logs/queue-signals-cron.log\n' "$BACKUP_DIR" "$APP_DIR"
