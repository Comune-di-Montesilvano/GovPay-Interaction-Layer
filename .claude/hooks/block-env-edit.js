/**
 * PreToolUse hook: block direct edits to .env (contains real secrets in dev).
 * Allows .env.example, .env.local, .env.ci, etc.
 * Exit 2 = hard block (tool call aborted).
 */

const input = JSON.parse(process.env.CLAUDE_TOOL_INPUT || '{}');
const filePath = (input.file_path || '').replace(/\\/g, '/');

if (filePath === '.env' || filePath.endsWith('/.env')) {
  process.stderr.write(
    'BLOCKED: direct .env edit not allowed.\n' +
    'Use .env.example for templates. If truly needed, edit manually in the shell.\n'
  );
  process.exit(2);
}
