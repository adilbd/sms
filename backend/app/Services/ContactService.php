<?php

namespace App\Services;

use App\Models\ContactMessage;

class ContactService
{
    public const RULES = [
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'phone' => 'nullable|string|max:30',
        'subject' => 'nullable|string|max:255',
        'message' => 'required|string|max:5000',
    ];

    public function submit(array $data, string $source, ?string $ip): ContactMessage
    {
        return ContactMessage::create([
            ...collect($data)->only(array_keys(self::RULES))->all(),
            'source' => $source,
            'ip_address' => $ip,
        ]);
    }
}
