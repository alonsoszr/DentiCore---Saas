<?php

/*
 * Traducción al español solo de las reglas usadas por la API hasta ahora. Cualquier
 * mensaje no incluido aquí cae al idioma de respaldo (APP_FALLBACK_LOCALE=en).
 */
return [
    'alpha_dash' => 'El campo :attribute solo puede contener letras, números, guiones y guiones bajos.',
    'array' => 'El campo :attribute debe ser un conjunto de datos.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'exists' => 'El valor de :attribute no es válido.',
    'in' => 'El valor de :attribute no es válido.',
    'max' => [
        'string' => 'El campo :attribute no debe superar los :max caracteres.',
    ],
    'min' => [
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'required' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser un texto.',
    'unique' => 'El :attribute ya está en uso.',
    'uuid' => 'El campo :attribute no es válido.',

    'attributes' => [
        'admin' => 'administrador',
        'birth_date' => 'fecha de nacimiento',
        'document_id' => 'documento de identidad',
        'email' => 'correo electrónico',
        'first_name' => 'nombres',
        'is_active' => 'estado',
        'last_name' => 'apellidos',
        'medical_history' => 'antecedentes médicos',
        'medical_history.alergias' => 'alergias',
        'medical_history.alergias.*' => 'alergia',
        'medical_history.enfermedades' => 'enfermedades',
        'medical_history.enfermedades.*' => 'enfermedad',
        'medical_history.medicamentos' => 'medicamentos',
        'medical_history.medicamentos.*' => 'medicamento',
        'medical_history.observaciones' => 'observaciones',
        'name' => 'nombre',
        'password' => 'contraseña',
        'phone' => 'teléfono',
        'role' => 'rol',
        'settings' => 'configuración',
        'slug' => 'código de acceso',
        'subscription_plan' => 'plan',
        'tenant_slug' => 'código de clínica',
        'user_uuid' => 'cuenta de portal',
    ],
];
