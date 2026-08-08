@if($get('pan_status') === 'checking')
    <div class="text-primary-600 text-sm font-medium">
        ⏳ Checking PAN...
    </div>
@elseif($get('pan_status') === 'available')
    <div class="text-success-600 text-sm font-medium">
        ✅ PAN Available
    </div>
@elseif($get('pan_status') === 'exists')
    <div class="text-danger-600 text-sm font-medium">
        ⚠️ Existing Customer Found
    </div>
@endif
