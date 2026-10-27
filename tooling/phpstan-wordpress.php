<?php

/**
 * WordPress signatures for static analysis only. This file is never loaded at runtime.
 */

/** @param callable|string $callback */
function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool {}

/** @param callable|string $callback
 *  @return string|false
 */
function add_options_page(string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '') {}

/** @param array<string, mixed> $args */
function register_setting(string $option_group, string $option_name, array $args = []): void {}

/** @param callable $callback
 *  @param array<string, mixed> $args
 */
function add_settings_section(string $id, string $title, $callback, string $page, array $args = []): void {}

/** @param callable $callback
 *  @param array<string, mixed> $args
 */
function add_settings_field(string $id, string $title, $callback, string $page, string $section = 'default', array $args = []): void {}

function current_user_can(string $capability): bool {}

/** @param mixed $default
 *  @return mixed
 */
function get_option(string $option, $default = false) {}

function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void {}
function __(string $text, string $domain = 'default'): string {}
function __return_false(): bool {}
function esc_html(string $text): string {}
function esc_attr(string $text): string {}
function esc_url(string $url): string {}
function admin_url(string $path = ''): string {}
function settings_errors(string $setting = '', bool $sanitize = false, bool $hide_on_update = false): void {}
function settings_fields(string $option_group): void {}
function do_settings_sections(string $page): void {}
function submit_button(): void {}
