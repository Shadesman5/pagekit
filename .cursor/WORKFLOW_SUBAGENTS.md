# Pagekit Modernization: Subagent Workflow (How to Use)

**Stand:** 2026-02-03

This document explains how to run the Pagekit modernization using Cursor’s **subagents** and the **Orchestrator** workflow, so the main (remote) agent delegates work and keeps context clean.

---

## 0. Prompt Scope: Normal vs Exception

**Normal pattern (what you usually do):**  
**One ROADMAP step = one or more prompts.** Full concentration on that single step. Example: one prompt for step 1.5 (DBAL), another for 1.6 (PSR-11). The orchestrator then has only one step in the checklist for that run.

**Exception (this audit):**  
**One prompt = many steps.** Example: `AGENT_PROMPT_AUDIT_PHASE1.md` – one prompt that tells the agents to analyze and audit **every** step 1.1–1.14 in a single run. The orchestrator checklist has many steps (one per ROADMAP ID).

**For audits you can do either:**

| Approach | Description | When to use |
|----------|-------------|-------------|
| **Per-step audit** | One (or more) audit prompts **per** ROADMAP step (e.g. `AGENT_PROMPT_AUDIT_STEP_1_5.md`). Each run audits only that step; append to shared audit report. | Matches your normal workflow; full focus per step; easier to re-run a single step; one commit per step. |
| **One audit for all** | One prompt for the whole phase (e.g. current `AGENT_PROMPT_AUDIT_PHASE1.md`). One run, all steps, one report. | Good for a single full Phase 1 health check run; more context in one go. |

**Recommendation:** For consistency with "one step, full concentration" and with the orchestrator (commit per step), **per-step audit prompts** are the better fit. Use "one prompt for all steps" only when you explicitly want one big audit run (e.g. initial Phase 1 verification). **Template for per-step audits:** `migration-docs/TODO/agent_prompts/AGENT_PROMPT_AUDIT_STEP_TEMPLATE.md` – copy, rename (e.g. `AGENT_PROMPT_AUDIT_STEP_1_5.md`), replace step ID and topic.

---

## 1. How Cursor Subagents Work

- **Subagents** are separate AI “workers” the main agent can **delegate** to. Each has its own context window, so long task output doesn’t fill the main chat.
- **Your subagents** are defined in `.cursor/agents/`:
  - `architect.md` – Plans scope, checklist, TODO-Spec (ROADMAP-aligned).
  - `refactorer.md` – Executes one step at a time (code/docs), “No Mercy” style.
  - `verifier.md` – Checks Refactorer output (no shims, correct ROADMAP IDs).
  - `tester.md` – Runs setup, PHPUnit, Playwright; does RCA on failure.
- The **main agent** (the one you talk to) **invokes** them by **name** in natural language, e.g. “Delegate to the architect subagent to …”. Cursor then runs the matching agent from `.cursor/agents/`.
- **Rule that ties it together:** `.cursor/rules/orchestrator-subagent-workflow.mdc`  
  It applies when a **task prompt** is in context (e.g. `migration-docs/TODO/agent_prompts/*.md` or `**/PROMPT_*.md`). It tells the main agent: do not run the whole prompt yourself; delegate to architect first, then refactorer → verifier → tester per step, and commit per step.

---

## 2. How You Invoke a Task (Remote Agent)

**Option A – With @-mention (recommended)**

1. Put the **task prompt** in context, e.g.  
   `@migration-docs/TODO/agent_prompts/AGENT_PROMPT_AUDIT_PHASE1.md`
2. Add the **ROADMAP** so step IDs are clear:  
   `@.cursor/ROADMAP.md`
3. In one message, ask the agent to execute the task, e.g.:

   ```
   Execute the task defined in the attached prompt (@AGENT_PROMPT_AUDIT_PHASE1.md).
   Follow the Orchestrator workflow: Architect → Refactorer → Verifier → Tester per step.
   Reference: @ROADMAP.md. Commit after each step (Conventional Commits).
   ```

Because the prompt path matches the rule’s `globs`, the **orchestrator rule** is applied and the agent should delegate to subagents instead of doing everything itself.

**Option B – Use the invocation template**

Use `.cursor/PROMPT_TASK_INVOCATION_TEMPLATE.md`: copy the “Invocation Block”, replace the prompt file name, and paste into the remote agent. That block already tells the agent to use the Orchestrator workflow and ROADMAP.

---

## 3. What Happens Step by Step

| Phase | Who | What |
|-------|-----|------|
| 1 | **You** | Send task prompt + ROADMAP + “Execute … Orchestrator workflow”. |
| 2 | **Main agent** | Reads orchestrator rule; delegates to **architect** (does not run the full prompt itself). |
| 3 | **Architect** | Returns: scope, checklist (one entry per logical step), TODO-Spec, deferred items. |
| 4 | **Main agent** | For **each** checklist step: delegates to **refactorer** → **verifier** → **tester**, then commits. |
| 5 | **Refactorer** | Does only the **current** step (verify/fix code and docs, update audit report). |
| 6 | **Verifier** | Checks Refactorer output (no adapters/shim, correct ROADMAP IDs). |
| 7 | **Tester** | Runs `php pagekit setup`, PHPUnit, Playwright as needed. |
| 8 | **Main agent** | Commits (Conventional Commits), then repeats for the next step until the checklist is done. |

So: **you** only trigger the task once; the **main agent** is responsible for calling architect, then refactorer/verifier/tester in a loop and committing per step.

---

## 4. How to Write Task Prompts for This Workflow

- **Keep prompts slim.** Avoid duplicating ROADMAP or `.cursor/rules`; reference them instead.
- **State at the top** that the Orchestrator workflow applies (e.g. “Follow orchestrator-subagent-workflow; delegate to architect first …”).
- **Define:** goal, branch, output paths, and **scope** (e.g. ROADMAP IDs 1.1–1.14). Use **ROADMAP as the source of truth** for step IDs and task names; don’t add a second “detail” doc unless you really need it.
- If you reference a second doc (e.g. PHASE#1) for historical detail, label it as **optional – use with caution; may be outdated** so the agent verifies against code.
- **One logical step = one Refactorer run = one commit.** So the Architect’s checklist should list steps that match “one commit each” (e.g. one ROADMAP ID or one coherent change set).

Example: `AGENT_PROMPT_AUDIT_PHASE1.md` – ROADMAP = truth, PHASE#1 = optional context with an explicit "may be outdated" warning.

---

## 5. Subagent Definitions: Are Yours OK?

Your four agents are **correctly set up** for Cursor:

- **Location:** `.cursor/agents/` (project-level; Cursor also supports `~/.cursor/agents/` for user-wide agents).
- **Format:** Markdown with YAML frontmatter: `name`, `description`, and optionally `model`.
- **Names:** `architect`, `refactorer`, `verifier`, `tester` – these are the names the orchestrator rule uses for delegation.

Optional tweaks:

- **Verifier** has no `model` in frontmatter; others use `claude-4.5-opus-high-thinking`. You can add a `model` line to `verifier.md` if you want to fix the model there too.
- **Descriptions** are already clear for when the main agent chooses which subagent to call.

No structural changes are required for “how to create or control” them: **control** is done by the **orchestrator rule** and by **your invocation message** (task prompt + “execute with Orchestrator workflow”).

---

## 6. Tips and Limitations

- **Rule must apply.** The orchestrator rule uses `globs: migration-docs/TODO/agent_prompts/*.md,**/PROMPT_*.md`. So the **task prompt file** must be in context (e.g. @-mentioned) and match one of these patterns; then the main agent gets the “delegate, don’t do it all yourself” behavior.
- **Explicit delegation.** The rule now includes exact phrases (“Delegate to the **architect** subagent to …”). That makes it clear how the main agent should “steer” each subagent.
- **One step at a time.** The rule forbids parallel steps and batch commits; that keeps history and rollbacks clear.
- **If the main agent ignores the workflow:** Paste the delegation phrases from the rule into your message (e.g. “First delegate to the architect subagent to create scope and checklist, then …”) and ensure the task prompt is attached so the rule is active.

---

## 7. Quick Reference

| You want to… | Do this… |
|--------------|----------|
| Run an audit/refactor task | @-mention the task prompt + ROADMAP, say “Execute … Orchestrator workflow”. |
| Add a new task prompt | Create a `.md` under `migration-docs/TODO/agent_prompts/` or named `PROMPT_*.md`; keep it slim and reference ROADMAP. |
| Change subagent behavior | Edit the corresponding `.cursor/agents/<name>.md` (prompt + frontmatter). |
| Change the workflow (order, commits) | Edit `.cursor/rules/orchestrator-subagent-workflow.mdc`. |
| Reuse the same invocation | Use `.cursor/PROMPT_TASK_INVOCATION_TEMPLATE.md` and swap the prompt file name. |

---

**Summary:** You trigger one task; the main agent delegates to **architect** (plan) then **refactorer** → **verifier** → **tester** per step and commits after each step. Subagents are configured in `.cursor/agents/` and “steered” by the orchestrator rule and your invocation message.
