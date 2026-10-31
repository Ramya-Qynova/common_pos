# OPEN_QUESTIONS.md

Per the Autonomy Charter, this is **not** a stopping point — it is a list of low-risk items a human may want to review later. All items below have already been decided (see `docs/DECISIONS.md`) and do not block progress.

- D-0001: PHP 8.2.12 used locally instead of the 8.3 minimum / 8.5 target — review when the dev machine's PHP is upgradeable without risking the unrelated `pos` project that shares it.
- D-0003: Project lives at `C:\xampp\htdocs\common_pos`, a new sibling directory to the existing `C:\xampp\htdocs\pos` — confirm this is where the operator wants it long-term (e.g. local vhost name, deployment path).
