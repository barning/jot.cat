<?php

if (! defined('ABSPATH')) {
	exit;
}

echo JotLibrary\Saved_Links_Block::render($attributes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes dynamic values.
