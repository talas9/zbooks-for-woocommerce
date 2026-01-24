# Compact State Protocol (CSP) v1
# Agent communication protocol. Lossless, token-minimal, deterministic.

## SCOPE
- Agent<->Subagent comms: CSP only
- Human<->Agent comms: Natural language
- Memory graph: CSP nodes/patches

## GOALS
1. **Lossless**: Exact intent preserved
2. **Token-minimal**: Deltas only, no restate
3. **Deterministic**: Fixed grammar, no synonyms
4. **Auditable**: Full state reconstruction

## MESSAGE FORMAT
```
CSP/1
FROM <AGENT_ID>
TO <AGENT_ID>
CTX <STATE_VER|CHECKPOINT_ID>
INTENT <PLAN|TASK|RESULT|ERROR|QUERY|PATCH|FILE|MEM>

<BODY>
```

## INTENT TYPES

### TASK (work order)
```
TASK T001
REQ <what to do>
IN <scope/files>
OUT <expected output type>
OWNER <agent_id>
PRIORITY P1|P2|P3
```

### RESULT (response to TASK)
```
RESULT T001
STATUS OK|PARTIAL|FAIL
PRODUCED <IDs/files>
DATA <content>
```

### ERROR
```
ERROR E001
AT T001
CAUSE <reason>
NEXT <suggested action>
```

### PATCH (delta update)
```
PATCH P001
BASE C0
+ DEF NEW_ITEM = value
~ DEF OLD_ITEM := new_value
- DEF REMOVED_ITEM
APPLY -> C1
```

### FILEOP
```
FILEOP F001 PATCH
PATH src/file.ts
BASE hash123
CONTENT
<<<
@@ -100,3 +100,5 @@
+new_line_here
>>>
```

## STATE MANAGEMENT
```
C0 (init) -> P1 -> C1 -> P2 -> C2 ...

Checkpoint: Frozen state snapshot
Patch: Delta from checkpoint
Reference: Always by ID (C0, P1, T001)
```

## TOKEN RULES
1. **!restate**: Reference by CTX, never repeat LAWS/DEFs
2. **PATCH only**: Never full rewrite
3. **IDs over prose**: After first DEF, use ID only
4. **No filler**: No politeness, no explanations
5. **Fixed terms**: Same label always, no synonyms

## PROJECT DEFs
```
DEF SRC = src/
DEF LIB = lib/
DEF TESTS = tests/

DEF CODE_EXP = code-explorer
DEF WEB_RES = web-researcher
DEF REF_FIND = ref-finder
DEF CODER = coder
```

## PROJECT LAWS
```
LAWS
- L1: Web search/fetch -> WEB_RES only
- L2: Codebase exploration -> CODE_EXP only
- L3: Refactoring -> REF_FIND first
- L4: Multi-file edits -> CODER only
```

## EXAMPLES

### Task Assignment
```
CSP/1
FROM MAIN
TO CODE_EXP
CTX C0
INTENT TASK

TASK T001
REQ find auth middleware
IN SRC,LIB
OUT FILE_REFS
OWNER CODE_EXP
PRIORITY P1
```

### Task Result
```
CSP/1
FROM CODE_EXP
TO MAIN
CTX C0
INTENT RESULT

RESULT T001
STATUS OK
PRODUCED FILE_REFS_001
DATA src/middleware/auth.ts:24-67;lib/utils/jwt.ts:12-45
```

### Error Response
```
CSP/1
FROM CODE_EXP
TO MAIN
CTX C0
INTENT ERROR

ERROR E001
AT T001
CAUSE no matches found in SRC,LIB
NEXT try broader scope or different keywords
```

## TASK_TPL (for spawning subagents)
```
CSP/1;!prose;DATA ONLY
RESULT{STATUS,DATA[file:line]}
TASK REQ:<req> IN:<scope> OUT:<type>
```

## VALIDATION
Valid CSP message must have:
- [ ] CSP/1 header
- [ ] FROM/TO agents
- [ ] CTX reference
- [ ] INTENT type
- [ ] Matching body block
