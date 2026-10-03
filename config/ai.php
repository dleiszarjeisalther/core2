<?php
/**
 * OpenAI configuration for the HRIS AI Assistant.
 * Set OPENAI_API_KEY in the hosting/server environment; never hard-code it here.
 */
function hris_openai_api_key(): string {
    return trim((string)(getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '')));
}

function hris_openai_model(): string {
    return trim((string)(getenv('OPENAI_MODEL') ?: ($_ENV['OPENAI_MODEL'] ?? 'gpt-5.6-luna')));
}

function hris_openai_enabled(): bool {
    return hris_openai_api_key() !== '';
}
