# AGENTS — LLM Auto Redirect

This project uses decentralized task-based coordination to allow multiple AI agents to work together on the plugin safely.

## Required flow

1. **List Tasks**: Read `.agent-tasks.json` with `python3 scripts/tasks.py list`.
2. **Claim Task**: Claim a task using `python3 scripts/tasks.py claim <task-id>`.
3. **Lock Files**: Lock the files you'll edit with `python3 scripts/lock.py <file> "<reason>"`.
4. **Complete Work**: Implement and write code.
5. **Verify Complete**: Verify completion with `python3 scripts/tasks.py verify-complete <task-id>`.
6. **Unlock Files**: Release file locks with `python3 scripts/unlock.py <file>`.

## Swarm coordination rules

- **One Task Limit**: Each agent may only claim one task at a time.
- **Concurrent Safety**: File locks are mandatory and prevent agents from clobbering each other's edits.
- **Verification Gate**: The `verify-complete` check verifies that target files exist and are not empty.
- **Metadata Sync**: Never edit `.agent-manifest.json` or `.agent-status.md` manually; let the scripts update them.

## Key scripts

- `python3 scripts/tasks.py` — Manage the task board.
- `python3 scripts/lock.py` — Acquire a lock on a file.
- `python3 scripts/unlock.py` — Release a lock on a file.
- `python3 scripts/status.py` — Check locks and active agents.
- `python3 scripts/heartbeat.py` — Send heartbeats to show you are active.
- `python3 scripts/force-unlock.py` — Force unlock files if an agent crashes (>600s stale).
- `python3 scripts/cleanup.py` — Clear stale coordination state.

## Quick start for new swarm members

```bash
# View task board
python3 scripts/tasks.py list

# Claim a task (e.g. task-001)
python3 scripts/tasks.py claim task-001

# Lock the target file
python3 scripts/lock.py LLM_Auto_Redirect.php "Working on task-001"
```

## Session start and end (Spine)
If the `spine` MCP is available, call `session_brief(project="LLM-Auto-Redirect")` (or the absolute path of this repo) first and use `get_section` / `recall` instead of reading PROGRESS.md or CONTEXT.md whole. Without the MCP, run `spine brief` from the repo root. If neither works, ground normally.
Before you stop, call `checkpoint` with your agent name (e.g. `cursor`), what was done, what's next and any blockers. Save durable why/gotcha/decision facts with `record_memory`.
