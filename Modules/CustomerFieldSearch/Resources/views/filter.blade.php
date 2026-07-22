<div class="col-sm-6 form-group @if (isset($filters['customer_field'])) active @endif" data-filter="customer_field">
    <label>{{ __('Custom Field') }} <b class="remove" data-toggle="tooltip" title="{{ __('Remove filter') }}">×</b></label>
    <select name="f[customer_field]" class="form-control" @if (empty($filters['customer_field'])) disabled @endif>
        <option value=""></option>
        @foreach ($options as $field_id => $label)
            <option value="{{ $field_id }}" @if (!empty($filters['customer_field']) && (int) $filters['customer_field'] == $field_id)selected="selected"@endif>{{ $label }}</option>
        @endforeach
    </select>
</div>
