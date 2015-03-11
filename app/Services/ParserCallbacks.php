<?php

namespace MyBB\Core\Services;

use MyBB\Core\Database\Models\Post;

class ParserCallbacks
{
	public static function getPostLink($pid)
	{
		$post = Post::find($pid);

		return route('topics.showPost', [$post->topic->slug, $post->topic->id, $post->id]);
	}
}