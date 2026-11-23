---
paths:
  - 'app/Filament/Resources/Customers/**'
---

# Customers

## Continuity stand-ins pass role gates via actsForOwner()
A role-name gate on a journey stage (e.g. canWorkDisbursal() on Step 4 and Finalize Disbursal) must also allow CustomerForm::actsForOwner($record, JourneyModule::X), which wraps CustomerJourneyAccessService::actsForOwner(). That check is true for the backup of an active continuity rule, or the holder of an active emergency takeover, on that module. Don't use decide() for this: it returns Normal first for anyone with hierarchy access, so a Team Leader backing up their own Manager would be missed. Edit access goes only through CustomerResource::canEdit(). Callers reach it only through the delegation Gate, so ViewCustomer, EditCustomer::mount and the table EditAction all call canEdit(). Notifications about a customer also go to activeBackupIdsFor(). Backup coverage walks visibleSubordinateIds. Covered by tests/Feature/JourneyContinuity/BackupInheritsOwnerAccessTest.php.

## Stage moves: Step 4 is Manager-and-up, every move checks its boxes, empty boxes never lock
User report 2026-11-23: an approved file could not reach Disbursal. Cause: Step 4 and Finalize Disbursal were gated to hasRole('Manager'), but under skip-level reporting many Team Leaders report straight to a Cluster Manager, so only the Admin could ever disburse those teams' files. CustomerForm::canWorkDisbursal() is now the single gate: Admin, Manager, Cluster Manager, Business Head, Other Bank Support, or actsForOwner(DisbursalProcessing). Team Leaders still need a delegation. CustomerJourneyService::approve() and sanction() call ensureReadyForApproval() / ensureReadyForDisbursal(), which throw field-keyed ValidationExceptions. The stage buttons run the same check in mountUsing, through CustomerForm::refuseIncompleteStep(), before the confirmation opens, so errors appear under the boxes (data.<field>) and the file does not move. Required journey boxes lock past their step only once the saved record holds a value (CustomerForm::lockedPastStep): a disabled box that is still dehydrated is still validated, so an empty locked required box made every Save fail. Do not reintroduce section-level ->disabled() on Step 2/3. sanction() also handles 'on_hold' (stays approved). Covered by tests/Feature/CustomerJourneyStageMoveTest.php.
