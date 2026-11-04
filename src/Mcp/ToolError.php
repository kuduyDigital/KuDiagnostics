<?php
/**
 * Expected tool failure.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

/**
 * Its message is shown to the client; any other exception is replaced by a generic message.
 */
final class ToolError extends \RuntimeException {}
