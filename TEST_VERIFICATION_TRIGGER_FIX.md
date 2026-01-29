# Test Verification: Trigger Settings Fix

## Bug Fixed
Hardcoded defaults in `get_option()` were overriding user's "disabled" settings.

## Changes Applied
**Branch:** `fix/bulk-sync-bugs`  
**Commit:** `08f2c7c`

### Modified Files:
1. **src/Hooks/OrderStatusHooks.php** (line 96)
2. **src/Admin/OrdersTab.php** (line 177)

### Code Change:
```php
// OLD (BROKEN):
$triggers = get_option('zbooks_sync_triggers', [
    'sync_draft' => 'processing',  // ← Forces auto-sync
    'sync_submit' => 'completed',
    'create_creditnote' => 'refunded',
]);

// NEW (FIXED):
$triggers = get_option('zbooks_sync_triggers', []);
// If never configured, default to disabled (not auto-enabled).
if (empty($triggers)) {
    $triggers = [
        'sync_draft' => '',
        'sync_submit' => '',
        'create_creditnote' => '',
    ];
}
```

## Test Cases to Verify

### Test 1: Fresh Install (Never Configured)
**Expected:** All triggers default to disabled (empty string)
- Navigate to WooCommerce → Settings → zBooks → Orders
- Check trigger dropdowns show "— None —" selected
- Create order and change status → No auto-sync should occur

### Test 2: User Disables All Triggers
**Expected:** Settings are respected, no auto-sync occurs
1. Set all 3 triggers to "— None —"
2. Save settings
3. Create test order
4. Change order status to any status
5. **Verify:** No automatic sync to Zoho Books
6. Check `wp_options` table: `zbooks_sync_triggers` should contain empty strings

### Test 3: User Enables Specific Trigger
**Expected:** Only enabled trigger fires
1. Set `sync_submit` to "Completed"
2. Leave other triggers as "— None —"
3. Save settings
4. Change order to "Processing" → No sync
5. Change order to "Completed" → Sync should occur
6. Change order to "Refunded" → No sync

### Test 4: Settings Persistence
**Expected:** Saved settings persist across page reloads
1. Configure triggers with specific values
2. Save settings
3. Reload settings page
4. **Verify:** Dropdowns show previously saved values (not defaults)

## Database Check
```sql
SELECT * FROM wp_options WHERE option_name = 'zbooks_sync_triggers';
```

**Expected after user selects all "— None —":**
```
a:3:{s:10:"sync_draft";s:0:"";s:11:"sync_submit";s:0:"";s:17:"create_creditnote";s:0:"";}
```

## Success Criteria
✅ Fresh install defaults to disabled (not auto-enabled)  
✅ User can select "— None —" and it persists  
✅ Orders don't auto-sync when all triggers disabled  
✅ Specific triggers work when enabled  
✅ Settings survive page reload  

## Risk Assessment
**Low Risk:** Only changes default behavior for NEW installs or when settings are explicitly disabled.

**No Breaking Changes:** Existing users with configured triggers unaffected.

## Notes
- SetupWizard.php not modified (only saves options, doesn't fetch with defaults)
- Both hook handler and settings page now use same logic
- Empty string check prevents hardcoded defaults from overriding user choice
