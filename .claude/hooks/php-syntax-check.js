/**
 * PostToolUse hook: PHP syntax check via docker exec.
 * Fires after Edit/Write on .php files.
 * Source files are not live-mounted, so content is piped via stdin to
 * `php -l /dev/stdin` inside the running gil-backoffice container.
 * Exit 0 = ok, exit 1 = syntax error (Claude sees warning, self-corrects).
 */

const { spawnSync } = require('child_process');
const fs = require('fs');

const input = JSON.parse(process.env.CLAUDE_TOOL_INPUT || '{}');
const filePath = input.file_path || '';

if (!filePath.match(/\.php$/i)) process.exit(0);
if (!fs.existsSync(filePath)) process.exit(0);

const content = fs.readFileSync(filePath, 'utf8');

const result = spawnSync('docker', ['exec', '-i', 'gil-backoffice', 'php', '-l', '/dev/stdin'], {
  input: content,
  encoding: 'utf8',
  timeout: 10000,
});

if (result.error) {
  // docker not running or not found — skip silently
  process.exit(0);
}

if (result.status !== 0) {
  const msg = (result.stdout || '') + (result.stderr || '') || 'syntax error';
  process.stderr.write('[php-syntax-check] ' + msg.trim() + '\n');
  process.exit(1);
}
