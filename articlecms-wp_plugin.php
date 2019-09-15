<?php
/**
 * Plugin Name: Magaport Articls CMS
 * Plugin URI:  https://github.com/magaport/articlecms-wp_plugin
 * Description: Add a new Feed for articles: <code>/?feed=articles</code> or with active permalinks <code>/feed/articles</code>. Based on https://github.com/bueltge/Drafts-Feed.
 * Version:     0.1.0
 * Author:      Antonio Kamiya
 * Author URI:  https://twitter.com/antonio_kamiya
 * Licence:     GPLv2
 * Last Change: 09/16/2019
 */

//avoid direct calls to this file, because now WP core and framework has been used
if ( ! function_exists( 'add_filter' ) ) {
	header('Status: 403 Forbidden');
	header('HTTP/1.1 403 Forbidden');
	exit();
}

if (!class_exists('ArticleCMS')) {
	add_action('plugins_loaded', array( 'ArticleCMS', 'init' ) );
	
	class ArticleCMS {
		
		protected static $classobj  = NULL;
		
		public static $feed_slug    = 'articles';
		
		public static $widget_slug  = 'dashboard_recent_articles';
		
		public static $options_slug = 'article_feed_options';
		
		/**
		* Handler for the action 'init'. Instantiates this class.
		* 
		* @access  public
		* @return  $classobj
		*/
		public static function init() {
			
			NULL === self::$classobj && self::$classobj = new self();
			
			return self::$classobj;
		}
		
		/**
		 * Constructor, init in WP
		 * 
		 * @return  void
		 */
		public function __construct() {
			
			$options = $this->get_options();
			
			// if options allow the articles feed
			if ( 1 === $options[ 'feed' ] ) {
				// add custom feed
				add_action( 'init', array( $this, 'add_articles_feed' ) );
				// change query for custom feed
				add_action( 'pre_get_posts', array( $this, 'feed_content' ) );
			}
			
			// add dashboard widget
			// add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget') );
			// add multilingual possibility, load lang file
			// add_action( 'admin_init', array( $this, 'textdomain') );
		}
		
		/**
		 * Load language file for translations
		 * 
		 * @return  void
		 */
		public function textdomain() {
			
			$locale = get_locale();
			
			if ( 'en_US' === $locale )
				return;
			
			load_plugin_textdomain( 'article_feed', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}
		
		/**
		 * Return the articles
		 * 
		 * @param   Integer $post_per_page for count of articles
		 * @return  Array 
		 */
		public function get_articles() {
			
			$options = $this->get_options();
			
			$args = array(
				'post_type'      => 'post',
				'orderby'        => 'modified',
				'order'          => 'DESC'
			);
			
			$articles_query = new WP_Query( $args );
			
			return $articles_query->posts;
		}
		
		/**
		 * Customizes the query.
		 * It will bail if $query is not an object, filters are suppressed 
		 * and it's not our feed query.
		 *
		 * @param  WP_Query $query The current query
		 * @return void
		 */
		public function feed_content( $query ) {
			// Bail if $query is not an object or of incorrect class
			if ( ! $query instanceof WP_Query )
				return;

			// Bail if filters are suppressed on this query
			if ( $query->get( 'suppress_filters' ) )
				return;
			
			// Bail if it's not our feed
			if ( ! $query->is_feed( self::$feed_slug ) )
				return;

			// check auth
			$this->check_feed_auth();

//			$query->set( 'post_status', array( 'any' ) );
			$query->set( 'order', 'ASC' );
			$query->set( 'orderby', 'modified' );

			if (isset($_GET["since"]) && strlen($_GET["since"]) > 0) {
				add_filter( 'posts_where', array ( $this, 'filter_modified_since' ));
			}
		}
		
		/**
		 * Filter posts newer than 'since' param specified in URL
		 * 
		 * @param  String $where
		 * @return String
		 */
		function filter_modified_since( $where = '' ) {
			$since = $_GET["since"];
			$where .= " AND post_modified >= '$since'";
			
			return $where;
		}

		/**
		 * Get dashbaord content
		 * 
		 * @param  Array $articles
		 * @return void
		 */
		public function dashboard_recent_articles( $articles = FALSE ) {
			
			if ( ! $articles )
				$articles = $this->get_articles();
			
			if ( $articles && is_array( $articles ) ) {
				
				// Get options
				$options = $this->get_options();
				// Count all posts
				$post_count =  wp_count_posts();
				$count   = (int) $post_count->draft + $post_count->publish;
				
				$list = array();
				foreach ( $articles as $article ) {

					$url    = get_edit_post_link( $article->ID );
					$title  = _draft_or_post_title( $article->ID );
					$user   = get_userdata( $article->post_author );
					$author = $user->display_name;
					$item = "<h4><a href='$url' title='" . sprintf( __( 'Edit &#8220;%s&#8221;' ), esc_attr( $title ) ) . "'>" 
						. esc_html( $title ) . "</a> <small>" . sprintf( __( 'by %s, ', 'article_feed' ), esc_attr( $author ) ) . "<abbr title='" . get_the_time( __( 'Y/m/d g:i:s A' ), $articles ) . "'>" 
						. get_the_time( get_option( 'date_format' ), $article ) . '</abbr></small></h4>';
					
					if ( 0 === $options[ 'only_title' ]
						   && $the_content = preg_split( '#[\r\n\t ]#', strip_tags( $articles->post_content ), 11, PREG_SPLIT_NO_EMPTY ) )
						$item .= '<p>' . join( ' ', array_slice( $the_content, 0, 10 ) ) . ( 10 < count( $the_content ) ? '&hellip;' : '' ) . '</p>';
						
					$list[] = $item;
				}
			?>
			<ul>
				<li><?php echo join( "</li>\n<li>", $list ); ?></li>
			</ul>
			<p class="textright"><a href="edit.php" class="button"><?php printf( __( 'View all %d', 'article_feed' ), $count ); ?></a></p>
			<?php
			} else {
				
				_e( 'There are no artices at the moment', 'article_feed' );
			}
		}
		
		/**
		 * Control for settings
		 * 
		 * @return String
		 */
		public function widget_settings() {
			
			$options = $this->get_options();
			
			// validate and update options
			if ( 'post' == strtolower( $_SERVER['REQUEST_METHOD'] ) 
					 && isset( $_POST[ 'widget_id' ] ) && self::$widget_slug == $_POST[ 'widget_id' ]
					) {
				
				// reset
				$options[ 'feed' ] = $options[ 'only_title' ] = 0;
				
				if ( $_POST[ 'feed' ] )
					$options[ 'feed' ] = (int) $_POST[ 'feed' ];
				
				if ( $_POST[ 'only_title' ] )
					$options[ 'only_title' ] = (int) $_POST[ 'only_title' ];
				
				if (  $_POST[ 'posts_per_page' ] )
					$options[ 'posts_per_page' ] = (int) $_POST[ 'posts_per_page' ];
				
				update_option( self::$options_slug, $options );
			}
			?>
			<p>
				<label for="feed">
					<input type="checkbox" id="feed" name="feed" value="1" <?php checked( 1, $options[ 'feed' ] ); ?> /> <?php _e( 'Create Draft Feed?', 'article_feed' ); ?>
				</label>
			</p>
			<p>
				<label for="only_title">
					<input type="checkbox" id="only_title" name="only_title" value="1" <?php checked( 1, $options[ 'only_title' ] ); ?> /> <?php _e( 'Show only the title inside the dashboard widget?', 'article_feed' ); ?>
				</label>
			</p>
			<p>
				<label for="posts_per_page">
					<input type="text" id="posts_per_page" name="posts_per_page" value="<?php esc_attr_e( $options[ 'posts_per_page' ] ); ?>" size="2" /> <?php _e( 'How many items show inside the dashboard widget?', 'article_feed' ); ?>
				</label>
			</p>
			<?php
		}
		
		/**
		 * Get options
		 * 
		 * @return  Array
		 */
		public function get_options() {
			
			$defaults = array(
				'feed'           => 1,
				'only_title'     => 1,
				'posts_per_page' => 5
			);
			
			$args = wp_parse_args(
				get_option( self::$options_slug ),
				apply_filters( 'article_feed_options', $defaults )
			);
			
			return $args;
		}
		
		/**
		 * Add Dashbaord widget
		 * 
		 * @return  void
		 */
		public function add_dashboard_widget() {
			
			wp_add_dashboard_widget(
				self::$widget_slug,
				__( 'Recents Drafts', 'article_feed' ) . 
				' <small>' . __( 'of all authors', 'article_feed' ) . '</small>',
				array( $this, 'dashboard_recent_articles' ), // content
				array( $this, 'widget_settings' ) // control
			);
		}
		
		/**
		 * Add articles with key
		 * Use as key the var $feed_strings
		 * 
		 * @return  void
		 */
		public function add_articles_feed() {
			// set name for the feed
			// http://example.com/?feed=articles
			add_feed( self::$feed_slug, array( $this, 'get_feed_template' ) );
		}
		
		/**
		 * Loads the feed template
		 * 
		 * @return  void
		 */
		public function get_feed_template() {
			$template_path = plugin_dir_path(__FILE__)."feed-article.php";

			load_template( $template_path );
		}

		// action handler for feed authentication
		// https://jerickson.net/requiring-authentication-wordpress-feeds/
		function check_feed_auth() {
			if (!isset($_SERVER['PHP_AUTH_USER'])) {
				header('WWW-Authenticate: Basic realm="RSS Feeds"');
				header('HTTP/1.0 401 Unauthorized');
				echo 'Feeds from this site are private';
				exit;
			} else {
				if (is_wp_error(wp_authenticate($_SERVER['PHP_AUTH_USER'],$_SERVER['PHP_AUTH_PW']))) {
					header('WWW-Authenticate: Basic realm="RSS Feeds"');
					header('HTTP/1.0 401 Unauthorized');
					echo 'Username and password were not correct';
					exit;
				}
			}
		}
		
	} // end class
} // end if class exists
