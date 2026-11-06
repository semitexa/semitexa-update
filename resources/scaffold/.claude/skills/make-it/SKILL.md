---
name: make-it
description: 'Work on this Semitexa project until nothing is left. Use when the user says "make it", "/make-it", "давай make it", or asks to keep working through the backlog. Semitexa decides what comes next: an interview on an empty backlog, the next approved task, ideas to offer, or all done.'
---

# Make it

What to do next is Semitexa's answer, not yours to work out. This skill only keeps asking.

1. Run `bin/semitexa ai:make-it --json`.
2. Tell the architect what `say` says, in their language, and do the `steps`.
3. While `go_on` is true, go back to 1. When it is false, stop.

To keep it going unattended in Claude Code: `/loop /make-it`, paced by itself. A tick that finds work still running only re-arms. When `go_on` is false, end the loop: do not schedule another tick.

Do not take a task the command did not name, and do not approve anything yourself: that is the architect's word.
