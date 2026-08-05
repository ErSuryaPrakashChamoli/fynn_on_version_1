<x-filament-panels::page>

<div class="mb-4">

    <input
        type="text"
        wire:model.live.debounce.500ms="search"
        placeholder="Search Customer..."
        class="w-full rounded border p-2">

</div>

<div class="overflow-x-auto">

<table class="w-full border">

<thead>

<tr>

<th>ID</th>

<th>Customer</th>

<th>Mobile</th>

<th>Salary</th>

<th>Approved Loan</th>

<th>Journey</th>

<th></th>

</tr>

</thead>

<tbody>

@foreach($rows as $index=>$row)

<tr wire:key="row{{$row['id']}}">

<td>

{{$row['id']}}

</td>

<td>

<input

wire:model="rows.{{$index}}.customer_name"

class="w-full border rounded p-1">

</td>

<td>

<input

wire:model="rows.{{$index}}.mobile_no"

class="w-full border rounded p-1">

</td>

<td>

<input

wire:model="rows.{{$index}}.salary"

class="w-full border rounded p-1">

</td>

<td>

<input

wire:model="rows.{{$index}}.approved_loan_amount"

class="w-full border rounded p-1">

</td>

<td>

<select

wire:model="rows.{{$index}}.journey_status"

class="w-full border rounded p-1">

<option value="sfl">SFL</option>

<option value="underwriting">Underwriting</option>

<option value="approved">Approved</option>

<option value="sanctioned">Sanctioned</option>

<option value="disbursal">Disbursal</option>

</select>

</td>

<td>

<x-filament::button
wire:click="saveRow({{$row['id']}})">

Save

</x-filament::button>

</td>

</tr>

@endforeach

</tbody>

</table>

</div>

</x-filament-panels::page>
