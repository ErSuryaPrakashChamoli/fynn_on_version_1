---
paths:
  - 'app/Filament/Resources/Customers/**'
---

# Customers

## Continuity stand-ins pass role gates via actsForOwner()
A role-name gate on a journey stage (e.g. hasRole('Manager') on Step 4 or Finalize Disbursal) must also allow CustomerForm::actsForOwner($record, JourneyModule::X), which wraps CustomerJourneyAccessService::actsForOwner(). That check is true for the backup of an active continuity rule, or the holder of an active emergency takeover, on that module. Don't use decide() for this: it returns Normal first for anyone with hierarchy access, so a Team Leader backing up their own Manager would be missed. Edit access goes only through CustomerResource::canEdit(). Callers reach it only through the delegation Gate, so ViewCustomer, EditCustomer::mount and the table EditAction all call canEdit(). Notifications about a customer also go to activeBackupIdsFor(). Backup coverage walks visibleSubordinateIds. Covered by tests/Feature/JourneyContinuity/BackupInheritsOwnerAccessTest.php.
