<?php

/**
 * Habari UserRecord Class
 *
 * Requires PHP 5.0.4 or later
 * @package Habari
 * 
 * @todo TODO Fix this documentation!
 *
 * Includes an instance of the UserInfo class; for holding inforecords about the user
 * If the User object describes an existing user; use the internal info object to get, set, unset and test for existence (isset) of 
 * info records
 * <code>
 *	$this->info = new UserInfo ( 1 );  // Info records of user with id = 1
 * $this->info->option1= "blah"; // set info record with name "option1" to value "blah"
 * $info_value= $this->info->option1; // get value of info record with name "option1" into variable $info_value
 * if ( isset ($this->info->option1) )  // test for existence of "option1"
 * unset ( $this->info->option1 ); // delete "option1" info record
 * </code>
 *
 */
class User extends QueryRecord
{
	private static $identity= null;  // Static storage for the currently logged-in User record
	
	private $info= null;
 
	/**
	* static function default_fields
	* @return array an array of the fields used in the User table
	*/
	public static function default_fields()
	{
		return array(
			'id' => '',
			'username' => '',
			'email' => '',
			'password' => ''
		);
	}

	/**
	* constructor  __construct
	* Constructor for the User class
	* @param array an associative array of initial User fields
	*/
	public function __construct( $paramarray = array() )
	{
		// Defaults
		$this->fields = array_merge(
			self::default_fields(),
			$this->fields );
		parent::__construct($paramarray);
		$this->exclude_fields('id');
		$this->info= new UserInfo ( $this->fields['id'] );
		 /* $this->fields['id'] could be null in case of a new user. If so, the info object is _not_ safe to use till after set_key has been called. Info records can be set immediately in any other case. */

	}

	/**
	* function identify
	* checks for the existence of a cookie, and returns a user object of the user, if successful
	* @return user object, or false if no valid cookie exists
	**/	
	public static function identify()
	{
		// Is the logged-in user not cached already?
		if ( self::$identity == null ) {
			// see if there's a cookie
			$cookie= 'habari_' . Options::get('GUID');
			if ( ! isset($_COOKIE[$cookie]) ) {
				// no cookie, so stop processing
				return false;
			}
			else {
				$tmp= explode( '|', $_COOKIE[$cookie], 2 );
				if ( count( $tmp ) == 2 ) {
					list($userid, $cookiehash)= $tmp;
				}
				else {
					// legacy cookies
					//$userid= substr( $_COOKIE[$cookie], 40 );
					//$cookiehash= substr( $_COOKIE[$cookie], 0, 40 );
					return false;
				}
				// now try to load this user from the database
				$user= User::get_by_id( $userid );
				if ( ! $user ) {
					return false;
				}
				if ( Utils::crypt($user->password . $userid, $cookiehash) ) {
					// Cache the user in the static variable
					self::$identity = $user;
					return $user;
				}
				else {
					return false;
				}
			}
		}
		else {
			return self::$identity;
		}
	}
	
	/**
	 * function insert
	 * Saves a new user to the users table
	 */	 	 	 	 	
	public function insert()
	{
	   $result= parent::insert( DB::table('users') );
  	   $this->info->set_key( DB::last_insert_id() );
		 /* If a new user is being created and inserted into the db, info is only safe to use _after_ this set_key call. */
		// $this->info->option_default= "saved";

		return $result;
	}

	/**
	 * function update
	 * Updates an existing user in the users table
	 */	 	 	 	 	
	public function update()
	{
		return parent::update( DB::table('users'), array( 'id' => $this->id ) );
	}

	/**
	 * function delete
	 * delete a user account
	**/
	public function delete()
	{
		return parent::delete( DB::table('users'), array( 'id' => $this->id ) );
	}

	/**
	* function remember
	* sets a cookie on the client machine for future logins
	*/
	public function remember()
	{
		// set the cookie
		$cookie = "habari_" . Options::get('GUID');
		$content = $this->id . '|' . Utils::crypt( $this->password . $this->id );
		$site_url= Options::get('siteurl');
		if ( empty( $site_url ) ) {
			$site_url= rtrim( $_SERVER['SCRIPT_NAME'], 'index.php' );
		}
		setcookie( $cookie, $content, time() + 604800, $site_url );
	}

	/** function forget
	* delete a cookie from the client machine
	*/
	public function forget()
	{
		// delete the cookie
		$cookie = "habari_" . Options::get('GUID');
		$site_url= Options::get('siteurl');
		if ( empty( $site_url ) ) {
			$site_url= rtrim( $_SERVER['SCRIPT_NAME'], 'index.php' );
		}
		setcookie($cookie, ' ', time() - 86400, $site_url);
		$home = Options::get('base_url');
		header( "Location: " . $home );
		exit;
	}

	/**
	* Check a user's credentials to see if they are legit
	* -- calls all auth plugins BEFORE checking local database.
	* 
	* @todo Actually call plugins
	* 
	* @param string $who A username or email address
	* @param string $pw A password
	* @return a User object, or false
	*/
	public static function authenticate($who = '', $pw = '')
	{
		if ( (! $who ) || (! $pw ) ) {
			return false;
		}
		/*
			execute auth plugins here
		*/

		if ( strstr($who, '@') )
		{
			// we were given an email address
			$user= User::get_by_email( $who );
		}
		else
		{
			$user= User::get_by_name( $who );
		}
		if ( ! $user ) {
			self::$identity= null;
			return false;
		}
		if ( Utils::crypt( $pw, $user->password ) ) {
			// valid credentials were supplied
			// set the cookie
			$user->remember();
			self::$identity= $user;
			return self::$identity;
		}
		else {
			self::$identity= null;
			return false;
		}
	}

	/**
	* function get
	* fetches a user from the database by name, ID, or email address
	* this is a wrapper function that will invoke the appropriate
	* get_by_* method
	*/
	
	public static function get($who = '')
	{
		if ('' === $who) {
			return false;
		}
		$what = 'username';
		// was a user ID given to us?
		if ( is_int( $who ) ) {
			$user= User::get_by_id( $who );
		} elseif ( strstr($who, '@') ) {
			// was an email address given?
			$user= User::get_by_email( $who );
		} else {
			$user= User::get_by_name( $who );
		}
		// $user will be a user object, or false depending on the
		// results of the get_by_* method called above
		return $user;
	}

	/**
	 * function get_by_id
	 * select a user from the database by their ID
	 * @param int The user's ID
	 * @return user object, or false
	**/
	public static function get_by_id ( $id = 0 )
	{
		if ( ! $id ) {
			return false;
		}
		$user= DB::get_row( 'SELECT * FROM ' . DB::table('users') . ' WHERE id = ?', array( $id ), 'User' );
		return $user;
	}

	/**
	 * function get_by_name
	 * select a user from the database by their login name
	 * @param string the user's name
	 * @return user object, or false
	**/
	public static function get_by_name( $who = '' )
	{
		if ( '' === $who ) {
			return false;
		}
		$user= DB::get_row( 'SELECT * FROM ' . DB::table('users') . ' WHERE username = ?', array( $who ), 'User');
		return $user;
	}

	/**
	 * function get_by_email
	 * select a user from the database by their email address
	 * @param string the user's email address
	 * @return user object, or false
	**/
	public static function get_by_email( $who = '' )
	{
		if ( ! $who ) {
			return false;
		}
		$user= DB::get_row( 'SELECT * FROM ' . DB::table('users') . ' WHERE email = ?', array( $who ), 'User');
		return $user;
	}

	/**
	* function get_all()
	* fetches all the users from the DB.
	* still need some checks for only authors.
	*/
	
	public static function get_all()
	{
		$list_users = DB::get_results( 'SELECT * FROM ' . DB::table('users') . ' ORDER BY id DESC', array(), 'User' );
			if ( is_array( $list_users ) ) {
				return $list_users;
			} else {
				return array();
			}
	}

	/**
	 * function count_posts()
	 * returns the number of posts written by this user
	 * @param mixed A status on which to filter posts (approved, unapproved).  If FALSE, no filtering will be performed.  Default: Post::STATUS_APPROVED
	 * @return int The number of posts written by this user
	**/
	public function count_posts( $status = Post::STATUS_APPROVED )
	{
		return Posts::count_by_author( $this->id, $status );
	}
	
	/**
	 * Returns the karma of this person, relative to the object passed in.
	 * The object can be any object
	 * You will usually not actually call this yourself, but will instead
	 * call one of the functions following - is_admin(), is_drafter(), or
	 * is_publisher().
	 * 
	 * @param mixed $obj An object, or an ACL object, or an ACL name
	 * @return int $karma
	 */
	function karma( $obj= '' ) {
			// What was the argument?
	
			// It was a string, such as 'everything'.
			if ( is_string( $obj ) ) {
					$acl = new acl( $obj );
					return $acl->karma( $this );
	
			// It was an object  ....
			} elseif ( is_object( $obj ) ) {
					// What kind of object is it?
					$type = get_class( $obj );
	
					// Special case - acl object
					if ( $type == 'acl' ) {
							// It's already an ACL ...
							return $obj->karma( $user );
					} else {
							// It's some other object
							$acl = new acl( $obj );
							return $acl->karma( $user );
					}
			} else {
					// Run screaming from the room
					error_log("Weirdness passed to karma()");
					return 0;
			}
	
			// Special case - no argument
			if ($type == '') {
					// What's this users greatest karma, anywhere?
					$karma = DB::get_row( "SELECT max(karma) as k
							FROM  acl
							WHERE userid = ? ",
							array( $this->id ) );
					return $karma ? $karma->k : 0;
			} else {
					// Um ... how did we get here?
					error_log( "Not sure how we got here" );
			}
	}
	
	/**
	 * Returns 1 or 0 (true or false) indicating whether the person in
	 * question is an admin with respect to the object passed in. The
	 * argument can be an actual object (such as a page or cms object), or
	 * it can be the name of a module (such as 'registrar' or 'everything').
	 * In the event that no argument is passed, the return value will be the
	 * highest karma of this user with respect to anything. The implied
	 * meaning is "is this user an admin anywhere?"
	 * 
	 * @param mixed $obj
	 * @return boolean $return
	 */
	function is_admin( $obj = '' ) {
			return ( $this->karma($obj) == 10 or 
					( $obj != 'everything' and $this->karma('everything') == 10 ) )
					? 1 : 0 ;
	}
	
	/**
	 * Returns 1 or 0 (true or false) indicating whether the person in
	 * question is a publisher with respect to the object passed
	 * in. The meaning is the same as with the is_admin() function
	 * 
	 * @param object $obj
	 * @return boolean $return
	 */
	function is_publisher( $obj = '' ) {
			return ( $this->karma( $obj) >= 8 or 
					( $obj != 'everything' and $this->is_publisher('everything') ) )
					? 1 : 0 ;
	}
	
	/**
	 * Returns 1 or 0 (true or false) indicating whether the person in
	 * question is a drafter with respect to the object passed
	 * in. The meaning is the same as with the is_admin() function.
	 * 
	 * @param object $obj
	 * @return boolean $return
	 */
	function is_drafter( $obj = '' ) {
			return ( $this->karma( $obj) >= 5 or 
					( $obj != 'everything' and $this->is_drafter('everything') ) )
					? 1 : 0 ;
	}

	/**
	 * Magic method __get implementation. Captures
	 * requests for the info object so that it can be initialized properly when the constructor
	 * is bypassed (see PDO::FETCH_CLASS pecularities). Passes all other requests to parent
	 * @param string $name
	 * @return mixed $return the requested field value
	 */

	public function __get( $name )
	{
		if( $name == 'info' ) {
			if ( !isset( $this->info ) ) {
				$this->info= new UserInfo( $this->fields['id'] );
			}
			else {				
				$this->info->set_key( $this->fields['id'] );
			}
			return $this->info;			
		}
		return parent::__get( $name );
	}


}

?>
