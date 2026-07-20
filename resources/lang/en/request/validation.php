<?php
return [
    'required' => 'The :attribute field is required.',
    'unique' => 'The :attribute has already been taken.',
    'email' => 'The :attribute must be a valid email address.',
    'string' => 'The :attribute must be a string.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'min' => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max' => [
        'file' => 'The :attribute may not be greater than :max kilobytes.',
    ],
    'array' => 'The :attribute must be an array.',
    'integer' => 'The :attribute must be an integer.',
    'exists' => 'The selected :attribute is invalid.',
    'file' => 'The :attribute must be a file.',
    'mimes' => 'The :attribute must be a file of type: :values.',
    'numeric' => 'The :attribute must be a number.',
    'date' => 'The :attribute is not a valid date.',
    'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
    'distinct' => [
        'must_be_selected' => 'At least one :attribute must be selected.',
        'only_one_must_be_selected' => 'Only one :attribute must be selected.',
    ],
    'password' => [
        'letters' => 'The :attribute must contain at least one letter.',
        'mixed_case' => 'The :attribute must contain both uppercase and lowercase letters.',
        'numbers' => 'The :attribute must contain at least one number.',
        'symbols' => 'The :attribute must contain at least one symbol.',
        'uncompromised' => 'The :attribute has been compromised in a data breach. Please choose a different password.',
    ],
    'custom' => [
        'nif_rule' => 'The :attribute is not valid.',
    ],
    'attributes' => [
        'email' => 'email',
        'nif' => 'NIF',
        'phone_number' => 'phone number',
        'username' => 'username',
        'name' => 'name',
        'date_birthday' => 'date of birth',
        'document_id' => 'document ID',
        'document_file' => 'document file',
        'operation_areas' => 'operation areas',
        'price_rate' => 'price rate',
        'services_types' => 'service types',
        'password' => 'password',
        'documents' => 'documents',
        'today' => 'today',
        'avatar' => 'avatar',
        'address' => 'address',
        'postal_code' => 'postal code',
        'locality' => 'district'
    ]
];
