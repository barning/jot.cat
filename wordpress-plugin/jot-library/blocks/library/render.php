<?php
/** @var array<string,mixed> $attributes */
echo JotLibrary\Library_Block::render($attributes); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes dynamic values and returns block markup.
