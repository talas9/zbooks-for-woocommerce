# CLAUDE.md v1.0 - CSP/DSL Format
# Machine-readable project context for Claude. !human-readable.

## PROJECT
DEF NAME = zbooks-for-woocommerce
DEF DESC = WooCommerce to Zoho Books integration plugin - sync orders, invoices, payments, refunds
DEF STACK = PHP 8.2+, WordPress 6.9+, WooCommerce 10.4+, Zoho Books API
DEF ROOT = /Users/talas9/Projects/zbooks-for-woocommerce

## ENV
DEF NODE = 18+ (for E2E tests with Playwright)
DEF PHP = 8.2+
DEF DB = mysql (WordPress)

## PATHS
DEF SRC = src/
DEF LIB = lib/
DEF TESTS = tests/
DEF DOCS = docs/
DEF SKIP = build/,node_modules/,.git/,dist/,*.min.js

## AGENTS (built-in subagent_type + CSP instructions)
DEF CODE_EXP = Explore + AGENT_CSP_EXPLORE
DEF WEB_RES = general-purpose + AGENT_CSP_WEB
DEF REF_FIND = Explore + AGENT_CSP_REFS
DEF CODER = general-purpose + AGENT_CSP_CODER
DEF PLANNER = Plan + AGENT_CSP_PLAN

## RULES (R1-R14)

### AGENT ROUTING (R1-R6)
R1: WEB_RES-only (web search/fetch->WEB_RES; !brave_web_search,!WebFetch direct; causes 30%+ ctx bloat)
R2: CODE_EXP-search (codebase exploration->CODE_EXP)
R3: ref-before-refactor (refactoring->REF_FIND first; ensures no missed refs)
R4: orchestrator-mode (multi-file->spawn subtasks via CODER; !write direct)
R5: CSP-agent-comms (agent<->subagent=CSP; human<->agent=natural)
R6: task-csp-template (Task prompts MUST include AGENT_CSP_* prefix)

### WORKFLOW (R7-R10)
R7: plan-first (>1 file|>10 lines->todo_write first)
R8: read-before-edit (ALWAYS read file before editing)
R9: workflow-routing (see WF_ROUTE table below)
R10: phase-sequential (complete each phase before next; !skip REFS in WF_REFACTOR)

### SECURITY (R11-R12)
R11: !secrets-in-logs (!API keys,!passwords,!tokens in logs)
R12: !credentials-in-code (use env vars)

### CODE QUALITY (R13-R14)
R13: WordPress Coding Standards (WPCS) - run `./vendor/bin/phpcs` before commit
R14: PHPUnit tests required for new features - run `./vendor/bin/phpunit`

### WPORG COMPLIANCE (R15-R18)
R15: !load_plugin_textdomain (WordPress.org auto-loads translations since WP 4.6)
R16: readme.txt "Tested up to" must match current WordPress version (6.9)
R17: No .sh files in distribution (WordPress.org rejects shell scripts)
R18: composer.json required if vendor/ exists in distribution

## AGENT_CSP_* (prefix prompts for subagents)

### AGENT_CSP_EXPLORE (for CODE_EXP)
```
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:file:line refs only L2:!full content L3:!>2500tok L4:files_with_matches first
DEF SRC=src/ LIB=lib/ TESTS=tests/ SKIP=build/,node_modules/
RESULT FORMAT: STATUS OK|PARTIAL|FAIL; SCOPE [...]; DATA file:line-symbol; READ_RECS file:start-end
!PAT: grep -C 15->BAD; Read 500+ lines->BAD
TASK REQ:<task> IN:<scope> OUT:FILE_REFS
```

### AGENT_CSP_WEB (for WEB_RES)
```
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:max 3 results L2:!full page content L3:!>500tok L4:extract ONLY requested
RESULT FORMAT: STATUS OK|PARTIAL|FAIL; SOURCES <n>; DATA [1]title(url) KEY:fact [2]...
!PAT: all 10 results->BAD; full markdown->BAD; 5+ searches->BAD
TASK REQ:<query> DATA_NEEDED:<specific> OUT:WEB_DATA
```

### AGENT_CSP_REFS (for REF_FIND)
```
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:find ALL refs L2:!miss any L3:check indirect L4:!>2000tok
SEARCH: 1.definition 2.direct calls 3.imports 4.indirect(callbacks,strings)
RESULT FORMAT: STATUS OK|PARTIAL; SYMBOL <name>; TOTAL <n>; DEFINITION file:line; CALLS file:line; IMPORTS file:line; INDIRECT file:line; RISKS file:line-reason
!PAT: skip indirect->BAD; miss imports->BAD
TASK REQ:refs for <symbol> IN:<scope> OUT:ALL_REFS
```

### AGENT_CSP_CODER (for CODER)
```
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:Read before Edit L2:max 5 changes L3:!refactor beyond plan L4:report conflicts L5:!add comments
PROCESS: read->edit->verify->report
RESULT FORMAT: STATUS OK|PARTIAL|FAIL; PLANNED <n>; APPLIED <n>; [1]file:line-OK [2]file:line-CONFLICT:reason; ISSUES; NEXT
!PAT: Edit without Read->BAD; over-refactor->BAD; force conflict->BAD
TASK PLAN:[1]file:line-change [2]file:line-change OUT:APPLY_STATUS
```

### AGENT_CSP_PLAN (for PLANNER)
```
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:research first L2:identify all files L3:consider edge cases L4:!>3000tok
RESULT FORMAT: STATUS OK; FILES [file1,file2]; STEPS [1]step [2]step; RISKS; QUESTIONS
TASK REQ:<feature/task> SCOPE:<area> OUT:IMPL_PLAN
```

## TASK EXAMPLES

### Spawn CODE_EXP
```
Task(subagent_type="Explore", prompt="""
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:file:line refs only L2:!full content L3:!>2500tok
DEF SRC=src/ LIB=lib/ SKIP=build/,node_modules/
RESULT FORMAT: STATUS; SCOPE; DATA file:line-symbol
TASK REQ:find auth middleware IN:SRC,LIB OUT:FILE_REFS
""")
```

### Spawn WEB_RES
```
Task(subagent_type="general-purpose", prompt="""
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:max 3 results L2:!full page L3:!>500tok L4:extract ONLY requested
RESULT FORMAT: STATUS; SOURCES; DATA [1]title(url) KEY:fact
TASK REQ:WordPress REST API authentication DATA_NEEDED:code examples OUT:WEB_DATA
""")
```

### Spawn REF_FIND
```
Task(subagent_type="Explore", prompt="""
CSP/1 MODE;!prose;DATA ONLY
LAWS: L1:find ALL refs L2:check indirect L3:!>2000tok
RESULT FORMAT: STATUS; SYMBOL; TOTAL; DEFINITION; CALLS; IMPORTS; INDIRECT; RISKS
TASK REQ:refs for handleAuth IN:SRC,LIB,TESTS OUT:ALL_REFS
""")
```

## WF_ROUTE (R9 routing table)
```
TRIGGER                  -> WORKFLOW
─────────────────────────────────────
new feature/enhancement  -> WF_FEATURE
bug/error/broken         -> WF_FIX
complex bug/no obvious   -> WF_DEBUG
rename/move/restructure  -> WF_REFACTOR
question/investigate     -> WF_RESEARCH
project setup/scaffold   -> WF_INIT
write tests              -> WF_CREATE MODE:TEST
write docs               -> WF_CREATE MODE:DOCS
add config               -> WF_CREATE MODE:CONFIG
add library/API          -> WF_INTEGRATE
review code/PR           -> WF_REVIEW
```

## WORKFLOWS

### WF_FEATURE (new feature implementation)
```
PHASE 1: RESEARCH
  IF need external info -> WEB_RES
  Task(general-purpose, AGENT_CSP_WEB + "REQ:<topic> DATA_NEEDED:<specific>")

PHASE 2: EXPLORE
  -> CODE_EXP (find related code)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find <related> IN:SRC,LIB")

PHASE 3: PLAN
  -> PLANNER (design implementation)
  Task(Plan, AGENT_CSP_PLAN + "REQ:<feature> SCOPE:<area>")
  -> TodoWrite (create task list from plan)

PHASE 4: IMPLEMENT
  FOR each file group (max 5):
    -> CODER
    Task(general-purpose, AGENT_CSP_CODER + "PLAN:[1]file:line-change...")

PHASE 5: TEST
  -> CMD TEST
  IF fail -> fix -> retest

PHASE 6: COMMIT
  -> git add + commit
```

### WF_FIX (bug fix)
```
PHASE 1: REPRODUCE
  Understand bug, get error/behavior

PHASE 2: LOCATE
  -> CODE_EXP (find bug location)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find <error/symptom> IN:SRC")

PHASE 3: REFS (if refactoring needed)
  -> REF_FIND (find all usages)
  Task(Explore, AGENT_CSP_REFS + "REQ:refs for <symbol> IN:SRC,LIB,TESTS")

PHASE 4: FIX
  IF single file -> Edit direct
  IF multi-file -> CODER
  Task(general-purpose, AGENT_CSP_CODER + "PLAN:[1]file:line-fix...")

PHASE 5: TEST
  -> CMD TEST
  IF fail -> iterate

PHASE 6: COMMIT
  -> git add + commit
```

### WF_REFACTOR (code refactoring)
```
PHASE 1: REFS (REQUIRED - R3)
  -> REF_FIND (find ALL references)
  Task(Explore, AGENT_CSP_REFS + "REQ:refs for <symbol> IN:SRC,LIB,TESTS")
  !skip this phase

PHASE 2: PLAN
  -> TodoWrite (list all changes from REFS result)

PHASE 3: IMPLEMENT
  -> CODER (batch changes)
  Task(general-purpose, AGENT_CSP_CODER + "PLAN:[1]file:line-rename...")

PHASE 4: TEST
  -> CMD TEST + CMD LINT

PHASE 5: COMMIT
  -> git add + commit
```

### WF_RESEARCH (investigation only)
```
PHASE 1: SCOPE
  Define what to find

PHASE 2: SEARCH
  IF codebase -> CODE_EXP
  IF external -> WEB_RES

PHASE 3: REPORT
  Summarize findings to user (natural language)
  !CSP for human output
```

### WF_INIT (project setup/scaffolding)
```
PHASE 1: RESEARCH
  -> WEB_RES (best practices, boilerplate)
  Task(general-purpose, AGENT_CSP_WEB + "REQ:<framework> setup DATA_NEEDED:project structure,dependencies")

PHASE 2: PLAN
  -> PLANNER (design structure)
  Task(Plan, AGENT_CSP_PLAN + "REQ:project setup SCOPE:full")
  -> TodoWrite (setup checklist)

PHASE 3: SCAFFOLD
  Create directories, config files, base files
  IF multi-file -> CODER
  Task(general-purpose, AGENT_CSP_CODER + "PLAN:[1]create dir structure [2]config files...")

PHASE 4: DEPENDENCIES
  -> CMD (install deps)
  npm install / composer install / pip install

PHASE 5: VERIFY
  -> CMD BUILD + CMD DEV
  Confirm project runs
```

### WF_CREATE (new tests/docs/configs - consolidated)
```
MODE: TEST | DOCS | CONFIG

PHASE 1: EXPLORE
  -> CODE_EXP (find target code to test/document)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find <target> IN:SRC")

PHASE 2: RESEARCH (if needed)
  IF TEST -> WEB_RES (testing patterns)
  IF DOCS -> WEB_RES (doc standards)
  Task(general-purpose, AGENT_CSP_WEB + "REQ:<framework> <mode> patterns")

PHASE 3: PLAN
  -> TodoWrite (list files to create)
  TEST: list test cases
  DOCS: list sections
  CONFIG: list settings

PHASE 4: IMPLEMENT
  -> CODER
  Task(general-purpose, AGENT_CSP_CODER + "PLAN:[1]create test/doc file...")

PHASE 5: VERIFY
  IF TEST -> CMD TEST (run new tests)
  IF DOCS -> review output
  IF CONFIG -> CMD BUILD (verify config works)

PHASE 6: COMMIT
  -> git add + commit
```

### WF_DEBUG (deep debugging - extends WF_FIX)
```
PHASE 1: REPRODUCE
  Get exact error, steps to reproduce
  Capture logs/stack trace

PHASE 2: TRACE
  -> CODE_EXP (find error origin)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find <error message> IN:SRC")
  -> CODE_EXP (trace call stack)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find callers of <function> IN:SRC")

PHASE 3: HYPOTHESIZE
  List possible causes
  -> TodoWrite (hypotheses to test)

PHASE 4: INVESTIGATE
  FOR each hypothesis:
    Add logging/breakpoints
    -> CMD DEV (reproduce)
    Analyze output
    IF found -> PHASE 5
    ELSE -> next hypothesis

PHASE 5: FIX
  -> WF_FIX.PHASE4 (apply fix)
  IF multi-file -> CODER

PHASE 6: VERIFY
  -> CMD TEST
  Remove debug logging
  Confirm fix

PHASE 7: COMMIT
  -> git add + commit
```

### WF_REVIEW (code review/PR review)
```
PHASE 1: SCOPE
  Get PR/diff/files to review
  -> git diff or gh pr view

PHASE 2: EXPLORE
  -> CODE_EXP (understand changed code context)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find related to <changed files> IN:SRC")

PHASE 3: ANALYZE
  Check for:
  - Logic errors
  - Security issues (R11-R12)
  - Missing tests
  - Style violations
  - Performance concerns

PHASE 4: REFS (if changes affect shared code)
  -> REF_FIND
  Task(Explore, AGENT_CSP_REFS + "REQ:refs for <changed symbol> IN:SRC,TESTS")
  Verify all usages still work

PHASE 5: REPORT
  Summarize findings (natural language)
  List: APPROVE | REQUEST_CHANGES | COMMENTS
  !CSP for human output
```

### WF_INTEGRATE (add external lib/API)
```
PHASE 1: RESEARCH
  -> WEB_RES (lib docs, examples)
  Task(general-purpose, AGENT_CSP_WEB + "REQ:<library> usage DATA_NEEDED:install,config,examples")

PHASE 2: EXPLORE
  -> CODE_EXP (find integration points)
  Task(Explore, AGENT_CSP_EXPLORE + "REQ:find <related feature> IN:SRC")

PHASE 3: PLAN
  -> PLANNER
  Task(Plan, AGENT_CSP_PLAN + "REQ:integrate <library> SCOPE:<area>")
  -> TodoWrite (integration steps)

PHASE 4: INSTALL
  -> CMD (add dependency)
  npm install <lib> / composer require / pip install

PHASE 5: IMPLEMENT
  -> CODER (add integration code)
  Task(general-purpose, AGENT_CSP_CODER + "PLAN:[1]config [2]wrapper [3]usage...")

PHASE 6: TEST
  -> CMD TEST
  Add integration tests if needed (-> WF_CREATE MODE:TEST)

PHASE 7: COMMIT
  -> git add + commit
```

## !PATTERNS (anti-patterns)

### WEB !PATTERNS (R1)
!PAT DIRECT_SEARCH: brave_web_search() in main ->BAD; use WEB_RES
!PAT DIRECT_FETCH: WebFetch() in main ->BAD; use WEB_RES
!PAT MULTI_SEARCH: 5+ web searches ->BAD; 1-2 targeted via WEB_RES

### SEARCH !PATTERNS (R2)
!PAT GREP_EXPLORE: Grep for exploration ->BAD; use CODE_EXP
!PAT GREP_C15: grep -C 15 ->BAD; -C 3 max
!PAT READ_FULL_FILE: Read 500+ lines ->BAD; targeted reads

### WORKFLOW !PATTERNS (R3-R4, R9-R10)
!PAT SKIP_REFS: refactor without refs ->BAD; REF_FIND first (R3)
!PAT MONOLITHIC_EDIT: edit 10+ locations ->BAD; batch via CODER (R4)
!PAT SKIP_PHASE: jump to IMPLEMENT without EXPLORE ->BAD; follow WF phases (R10)
!PAT WRONG_WF: use WF_FIX for new feature ->BAD; use WF_ROUTE table (R9)
!PAT NO_TEST: skip TEST/VERIFY phase ->BAD; always verify
!PAT FIX_AS_DEBUG: simple bug with WF_DEBUG ->BAD; use WF_FIX (simpler)
!PAT INTEGRATE_NO_RESEARCH: add lib without WEB_RES ->BAD; research first
!PAT INIT_NO_PLAN: scaffold without PLANNER ->BAD; plan structure first
!PAT CREATE_NO_EXPLORE: write tests without finding target ->BAD; EXPLORE first

## CMD (build commands)
CMD BUILD = composer install --no-dev --optimize-autoloader
CMD TEST = ./vendor/bin/phpunit
CMD LINT = ./vendor/bin/phpcs
CMD DEV = wp-env start
CMD E2E = npx playwright test

## FILES (key files)
zbooks-for-woocommerce.php = Main plugin file, bootstrap, constants
src/Plugin.php = Main plugin singleton, service container, hooks
src/Api/ZohoClient.php = Zoho Books API client
src/Api/TokenManager.php = OAuth token management (encrypted storage)
src/Service/SyncOrchestrator.php = Order sync coordination
src/Admin/SetupWizard.php = First-time setup wizard
readme.txt = WordPress.org plugin readme (required)
composer.json = PHP dependencies (required if vendor/ exists)
.distignore = WordPress.org deploy exclusions

## WPORG_PACKAGING (WordPress.org Plugin Packaging Rules)
# These rules MUST be followed when creating plugin zip files for WordPress.org

### REQUIRED FILES (must be in zip)
REQ readme.txt = Plugin readme (required by WordPress.org)
REQ composer.json = Required if vendor/ directory exists
REQ zbooks-for-woocommerce.php = Main plugin file
REQ LICENSE = GPL-2.0+ license file

### FORBIDDEN FILES (will cause rejection)
!ALLOW *.sh = Shell scripts not permitted
!ALLOW .DS_Store = macOS files not permitted
!ALLOW *.md = Markdown files (use readme.txt instead)
!ALLOW tests/ = Test files not permitted
!ALLOW .git/ = Git directory not permitted
!ALLOW .github/ = GitHub directory not permitted
!ALLOW node_modules/ = Node modules not permitted
!ALLOW phpcs.xml* = PHPCS config not permitted
!ALLOW phpunit.xml* = PHPUnit config not permitted
!ALLOW composer.lock = Lock file not permitted
!ALLOW package*.json = NPM files not permitted

### BUILD REQUIREMENTS
REQ composer-no-dev = Must run `composer install --no-dev` (no dev dependencies)
REQ folder-structure = Zip must have plugin-slug/ as root folder
REQ tested-up-to = readme.txt "Tested up to" must match current WordPress version
REQ no-load-textdomain = Do NOT use load_plugin_textdomain() for WordPress.org hosted plugins (auto-loaded since WP 4.6)

### ZIP BUILD COMMAND (manual)
```bash
# 1. Install production dependencies only
composer install --no-dev --optimize-autoloader

# 2. Create plugin directory structure
mkdir -p dist/zbooks-for-woocommerce

# 3. Copy required files
cp zbooks-for-woocommerce.php LICENSE readme.txt composer.json dist/zbooks-for-woocommerce/
cp -R src assets languages vendor dist/zbooks-for-woocommerce/

# 4. Create zip (exclude forbidden files)
cd dist && zip -rq zbooks-for-woocommerce-VERSION.zip zbooks-for-woocommerce -x "*.DS_Store" -x "*.sh"

# 5. Cleanup
rm -rf dist/zbooks-for-woocommerce
```

### GITHUB WORKFLOW
# See .github/workflows/release.yml for automated release builds
# See .github/workflows/deploy-wporg.yml for WordPress.org SVN deploy
# See .distignore for file exclusion list

## CHECKLIST (before submit)
CHK TESTS: all tests pass
CHK SECURITY: no secrets exposed
CHK STYLE: follows project conventions

## VERSION
CSP_VER = 1.1
RULES_COUNT = 18
LAST_UPDATE = 2026-01-25
