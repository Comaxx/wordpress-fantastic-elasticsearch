<?php
namespace elasticsearch;

abstract class AbstractArchive
{
    protected $searched = false;
    protected $attempted = false;
    protected $total = 0;
    protected $scores = array();
    protected $page = 1;
    protected $search = '';
    protected $_ids;
    protected $_blogIds;

	public function __construct()
	{
		add_action('pre_get_posts', array(&$this, 'do_search'), 100);
		add_action('the_posts', array(&$this, 'process_search'));
	}

	public function do_search($wp_query)
	{
		if (!$wp_query->is_main_query() || is_admin() || $this->attempted) {
			return;
		}

		$this->attempted = true;

		$args = $this->facets($wp_query, isset($_GET['es']) ? $_GET['es'] : array());

		if ($args === null) {
			return;
		}

		$this->page = isset($wp_query->query_vars['paged']) && $wp_query->query_vars['paged'] > 0 ? $wp_query->query_vars['paged'] - 1 : 0;

		wp_reset_query();

		if (!isset($wp_query->query_vars['posts_per_page'])) {
			$wp_query->query_vars['posts_per_page'] = get_option('posts_per_page');
		}

		$this->search = isset($wp_query->query_vars['s']) ? urldecode(str_replace('\"', '"', $wp_query->query_vars['s'])) : '';

		$results = Searcher::search($this->search, $this->page, $wp_query->query_vars['posts_per_page'], $args, $this->search ? false : true);

		if ($results == null) {
			return null;
		}

		$this->total = $results['total'];
		$this->_ids = $results['ids'];
		$this->_blogIds = $results['blog_ids'];

		$this->searched = true;
	}

	public function process_search($posts)
	{
		if ($this->searched) {
			$this->searched = false;

			$posts = array();

			foreach ($this->_blogIds as $blog_id => $post_ids) {
			    if ( ! count($post_ids)) {
			        continue;
                }

                switch_to_blog($blog_id);

			    foreach ($post_ids as $post_id) {
			        /** @var \WP_Post $post */
                    $post = get_post($post_id);
                    $post->permalink = get_permalink($post_id);
                    $posts[] = $post;
                }
            }

            restore_current_blog();

			usort($posts, array(&$this, 'sort_posts'));
		}

		return $posts;
	}

	public function sort_posts($a, $b)
	{
	    return array_search($b->ID, $this->_ids) > array_search($a->ID, $this->_ids) ? -1 : 1;
	}

	abstract function facets($wp_query, $existing);
}

?>
