---
name: make-it
description: 'Work on this Semitexa project until nothing is left. Use when the user says "make it", "/make-it", "давай make it", or asks to keep working through the backlog. Semitexa decides what comes next: an interview on an empty backlog, the next approved task, ideas to offer, or all done.'
---

# Make it

What to do next is Semitexa's answer, not yours to work out. This skill only keeps asking.

1. Run `bin/semitexa ai:make-it --json`.
2. Tell the architect what `say` says, in their language, and do the `steps`. Every report starts with the `line` it gives: how far work, right and fast have come.
3. While `go_on` is true, go back to 1 at once, in the same turn. One task done is not a reason to stop. When it is false, finish its steps and stop; go back to 1 only if a step says so.

A step can take minutes: `ai:verify` runs the end-to-end tests. Wait for it in the same turn, rather than ending the turn while it runs in the background. In Claude Code, run it in the foreground with the Bash tool's longest timeout. Never close a task on a failing `ai:verify`. A failure you think the change did not cause is still a failure: file it as a task of its own, block this task on it, and say both in the report.

To keep it going unattended in Claude Code, run `/loop /make-it`; it paces itself. A tick runs tasks until `go_on` is false or something it started is still running. Only then does it re-arm, and every report ends with when the next tick is. When `go_on` is false, end the loop and finish the steps with the architect: an interview needs their answers, not a tick.

Do not take a task the command did not name, and do not approve anything yourself: that is the architect's word.
