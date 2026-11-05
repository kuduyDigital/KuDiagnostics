<?php
/**
 * Tools: kuduy-comment-list, kuduy-comment-moderate, kuduy-comment-reply (one class, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Like core's REST comments controller: only approved comments on readable posts without moderate_comments;
 * author email and IP only for moderators. Replies go through wp_new_comment() (kses, flood and duplicate checks).
 */
final class Comments implements Tool, Snapshottable {

	private const STATUS = array(
		'approve' => '1',
		'hold'    => '0',
		'spam'    => 'spam',
		'trash'   => 'trash',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode list | moderate | reply.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-comment-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'     => __( 'List comments', 'kuduy-mcp' ),
			'moderate' => __( 'Moderate a comment', 'kuduy-mcp' ),
			default    => __( 'Reply to a comment', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'list'     => 'Lists comments, newest first, optionally for one post. Status "approve" by default; "hold", "spam", "trash" and "all" need permission to moderate comments. Author email and IP are only shown to moderators.',
			'moderate' => 'Sets a comment to approve, hold, spam or trash. Snapshot first; undo with kuduy-snapshot-restore.',
			default    => 'Posts a comment as the connected user on a post, optionally as a reply to another comment. Approved right away for moderators, otherwise held for moderation.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		return match ( $this->mode ) {
			'list'     => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => $id,
					'status'  => array(
						'type'    => 'string',
						'enum'    => array( 'approve', 'hold', 'spam', 'trash', 'all' ),
						'default' => 'approve',
					),
					'search'  => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				) + Content::paging_input(),
				'additionalProperties' => false,
			),
			'moderate' => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'     => $id,
					'status' => array(
						'type' => 'string',
						'enum' => array_keys( self::STATUS ),
					),
				),
				'required'             => array( 'id', 'status' ),
				'additionalProperties' => false,
			),
			default    => array(
				'type'                 => 'object',
				'properties'           => array(
					'post_id' => $id,
					'parent'  => $id,
					'content' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 65525,
					),
				),
				'required'             => array( 'post_id', 'content' ),
				'additionalProperties' => false,
			),
		};//end match
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		$comment = array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'post_id'      => array( 'type' => 'integer' ),
				'parent'       => array( 'type' => 'integer' ),
				'author_name'  => array( 'type' => 'string' ),
				'author_id'    => array( 'type' => 'integer' ),
				'author_email' => array(
					'type'        => 'string',
					'description' => 'Moderators only.',
				),
				'author_ip'    => array(
					'type'        => 'string',
					'description' => 'Moderators only.',
				),
				'content'      => array( 'type' => 'string' ),
				'date_gmt'     => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
			),
			'required'   => array( 'id', 'post_id', 'author_name', 'content', 'status' ),
		);
		if ( 'list' !== $this->mode ) {
			$comment['properties']['_snapshot_id'] = array( 'type' => 'integer' );
			return $comment;
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'comments' => array(
					'type'  => 'array',
					'items' => $comment,
				),
				'total'    => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'comments', 'total', 'page', 'per_page' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'list' === $this->mode ? Content::read_only() : array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'moderate' === $this->mode,
			'idempotentHint'  => 'moderate' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'list' === $this->mode ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'moderate' === $this->mode ) {
			$comment = get_comment( (int) ( $input['id'] ?? 0 ) );
			return $comment instanceof \WP_Comment ? array( 'edit_comment', (int) $comment->comment_ID ) : 'moderate_comments';
		}
		if ( 'list' === $this->mode && 'approve' !== ( $input['status'] ?? 'approve' ) ) {
			return 'moderate_comments';
		}
		$post = Content::post( (int) ( $input['post_id'] ?? 0 ) );
		return null === $post ? 'read' : array( 'read_post', $post->ID );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$comment = 'moderate' === $this->mode ? get_comment( (int) $input['id'] ) : null;
		return $comment instanceof \WP_Comment ? array(
			'object_type' => 'comment',
			'object_id'   => (string) $comment->comment_ID,
			'payload'     => array(
				'id'     => (int) $comment->comment_ID,
				'status' => (string) $comment->comment_approved,
			),
		) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the comment is gone.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( ! get_comment( $id ) instanceof \WP_Comment ) {
			throw new ToolError( 'The comment no longer exists.' );
		}
		$status = (string) ( $payload['status'] ?? '0' );
		if ( 'trash' !== $status ) {
			wp_untrash_comment( $id );
			wp_unspam_comment( $id );
		}
		wp_set_comment_status( $id, array_flip( self::STATUS )[ $status ] ?? 'hold' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the post or comment is invalid or WordPress refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'list'     => $this->list( $input ),
			'moderate' => $this->moderate( $input ),
			default    => $this->reply( $input ),
		};
	}

	/**
	 * List comments.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the post is not readable.
	 */
	private function list( array $input ): array {
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$page     = (int) ( $input['page'] ?? 1 );
		$status   = (string) ( $input['status'] ?? 'approve' );
		$args     = array(
			'status'  => $status,
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
			'type'    => 'comment',
		);
		if ( isset( $input['post_id'] ) ) {
			if ( null === Content::post( (int) $input['post_id'] ) ) {
				throw new ToolError( 'Post ' . (int) $input['post_id'] . ' was not found.' );
			}
			$args['post_id'] = (int) $input['post_id'];
		}
		if ( isset( $input['search'] ) && '' !== $input['search'] ) {
			$args['search'] = (string) $input['search'];
		}
		$out = array();
		foreach ( get_comments( $args ) as $comment ) {
			// Approved comments of posts the user cannot read (drafts, private) stay hidden, as in core's REST API.
			if ( current_user_can( 'moderate_comments' ) || current_user_can( 'read_post', (int) $comment->comment_post_ID ) ) {
				$out[] = self::shape( $comment );
			}
		}
		$total = get_comments( array( 'count' => true ) + array_diff_key( $args, array_flip( array( 'number', 'offset' ) ) ) );
		return array(
			'comments' => $out,
			'total'    => is_int( $total ) ? $total : count( $out ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Change status.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the comment is missing or WordPress refuses.
	 */
	private function moderate( array $input ): array {
		$id = (int) $input['id'];
		if ( ! get_comment( $id ) instanceof \WP_Comment ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( sprintf( 'Comment %d was not found.', $id ) );
		}
		AuditLog::target( 'comment', (string) $id );
		$status = (string) $input['status'];
		$ok     = match ( $status ) {
			'trash' => wp_trash_comment( $id ),
			'spam'  => wp_spam_comment( $id ),
			default => (bool) wp_set_comment_status( $id, $status ),
		};
		if ( ! $ok ) {
			throw new ToolError( 'WordPress did not change the comment.' );
		}
		return self::shape( get_comment( $id ) );
	}

	/**
	 * Post a reply as the current user.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When commenting is not possible.
	 */
	private function reply( array $input ): array {
		$post = Content::post( (int) $input['post_id'] );
		if ( null === $post ) {
			throw new ToolError( 'Post ' . (int) $input['post_id'] . ' was not found.' );
		}
		$moderator = current_user_can( 'moderate_comments' );
		if ( ! $moderator && ( 'publish' !== $post->post_status || ! comments_open( $post ) ) ) {
			throw new ToolError( 'Comments are closed on this post.' );
		}
		$parent = (int) ( $input['parent'] ?? 0 );
		if ( $parent > 0 ) {
			$p = get_comment( $parent );
			if ( ! $p instanceof \WP_Comment || (int) $p->comment_post_ID !== $post->ID ) {
				throw new ToolError( 'The parent comment must belong to the same post.' );
			}
		}
		$user = wp_get_current_user();
		$id   = wp_new_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post->ID,
					'comment_parent'       => $parent,
					'comment_content'      => (string) $input['content'],
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_type'         => 'comment',
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new ToolError( 'WordPress refused the comment: ' . esc_html( $id->get_error_message() ) );
		}
		AuditLog::target( 'comment', (string) $id );
		$comment = get_comment( (int) $id );
		if ( ! $comment instanceof \WP_Comment ) {
			throw new ToolError( 'The comment was saved but could not be read back.' );
		}
		return self::shape( $comment );
	}

	/**
	 * Comment for output; email and IP only for moderators.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_Comment $comment ): array {
		$out = array(
			'id'          => (int) $comment->comment_ID,
			'post_id'     => (int) $comment->comment_post_ID,
			'parent'      => (int) $comment->comment_parent,
			'author_name' => $comment->comment_author,
			'author_id'   => (int) $comment->user_id,
			'content'     => $comment->comment_content,
			'date_gmt'    => $comment->comment_date_gmt,
			'status'      => (string) wp_get_comment_status( $comment ),
		);
		if ( current_user_can( 'moderate_comments' ) ) {
			$out['author_email'] = $comment->comment_author_email;
			$out['author_ip']    = $comment->comment_author_IP;
		}
		return $out;
	}
}
