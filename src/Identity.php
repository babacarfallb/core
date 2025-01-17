<?php
/**
 * This file is part of the Zemit Framework.
 *
 * (c) Zemit Team <contact@zemit.com>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace Zemit;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Ecdsa\Sha512;
use Phalcon\Acl\Role;
use Phalcon\Db\Column;
use Phalcon\Validation\Validator\Confirmation;
use Zemit\Di\Injectable;
use Phalcon\Messages\Message;
use Phalcon\Validation\Validator\PresenceOf;
use Zemit\Models\Session;
use Zemit\Models\User;

/**
 * Class Identity
 * {@inheritDoc}
 *
 * @author Julien Turbide <jturbide@nuagerie.com>
 * @copyright Zemit Team <contact@zemit.com>
 *
 * @since 1.0
 * @version 1.0
 *
 * @package Zemit
 */
class Identity extends Injectable
{
    /**
     * Without encryption
     */
    const MODE_DEFAULT = self::MODE_JWT;
    
    /**
     * Without encryption (raw string into the session)
     */
    const MODE_STRING = 'string';
    
    /**
     * Store using JWT (jwt encrypted into the session)
     */
    const MODE_JWT = 'jwt';
    
    /**
     * Locale mode for the prepare fonction
     * @var string
     */
    public string $mode = self::MODE_DEFAULT;
    
    /**
     * @var mixed|string|null
     */
    public $sessionKey = 'zemit-identity';
    
    /**
     * @var array
     */
    public $options = [];
    
    /**
     * @var User
     */
    public $user;
    
    /**
     * @var User
     */
    public $userAs;
    
    /**
     * @var Session
     */
    public $currentSession;
    
    /**
     * @var string|int|bool|null
     */
    public $identity;
    
    public function __construct($options = [])
    {
        $this->setOptions($options);
        $this->sessionKey = $this->getOption('sessionKey', $this->sessionKey);
        $this->setMode($this->getOption('mode', $this->mode));
//        $this->set($this->getFromSession());
    }
    
    /**
     * Set default options
     *
     * @param array $options
     */
    public function setOptions($options = [])
    {
        $this->options = $options;
    }
    
    /**
     * Getting an option value from the key, allowing to specify a default value
     *
     * @param $key
     * @param null $default
     *
     * @return mixed|null
     */
    public function getOption($key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }
    
    /**
     * @return string
     */
    public function getSessionClass()
    {
        return $this->getOption('sessionClass') ?? \Zemit\Models\Session::class;
    }
    
    /**
     * @return string
     */
    public function getUserClass()
    {
        return $this->getOption('userClass') ?? \Zemit\Models\User::class;
    }
    
    /**
     * @return string
     */
    public function getGroupClass()
    {
        return $this->getOption('groupClass') ?? \Zemit\Models\Group::class;
    }
    
    /**
     * @return string
     */
    public function getRoleClass()
    {
        return $this->getOption('roleClass') ?? \Zemit\Models\Role::class;
    }
    
    /**
     * @return string
     */
    public function getTypeClass()
    {
        return $this->getOption('roleClass') ?? \Zemit\Models\Type::class;
    }
    
    /**
     * @return string
     */
    public function getEmailClass()
    {
        return $this->getOption('emailClass') ?? \Zemit\Models\Email::class;
    }
    
    /**
     * Get the current mode
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }
    
    /**
     * Set the mode
     *
     * @param string $mode
     *
     * @throws \Exception Throw an exception if the mode is not supported
     */
    public function setMode($mode)
    {
        switch($mode) {
            case self::MODE_STRING:
            case self::MODE_JWT:
                $this->mode = $mode;
                break;
            default:
                throw new \Exception('Identity mode `' . $mode . '` is not supported.');
                break;
        }
    }
    
    /**
     * @return bool|mixed
     */
    public function getFromSession()
    {
        $ret = $this->session->has($this->sessionKey) ? $ret = $this->session->get($this->sessionKey) : null;
        
        if ($ret) {
            switch($this->mode) {
                case self::MODE_DEFAULT:
                    break;
                case self::MODE_JWT:
                    $ret = $this->jwt->parseToken($ret)->getClaim('identity');
                    break;
            }
        }
        
        return json_decode($ret);
    }
    
    /**
     * Save an identity into the session
     *
     * @param int|string|null $identity
     */
    public function setIntoSession($identity)
    {
        
        $identity = json_encode($identity);
        
        $token = null;
        switch($this->mode) {
            case self::MODE_JWT:
                $token = $this->jwt->getToken(['identity' => $identity]);
                break;
        }
        
        $this->session->set($this->sessionKey, $token ? : $identity);
    }
    
    /**
     * Set an identity
     *
     * @param int|string|null $identity
     */
    public function set($identity)
    {
        $this->setIntoSession($identity);
        $this->identity = $identity;
    }
    
    /**
     * Get the current identity
     * @return int|string|null
     */
    public function get()
    {
        $this->identity ??= $this->getFromSession();
        
        return $this->identity;
    }
    
    /**
     * @param array|null $roles
     * @param bool $or
     * @param bool $inherit
     *
     * @return bool
     */
    public function hasRole(?array $roles = null, bool $or = false, bool $inherit = true)
    {
        return $this->has($roles, array_keys($this->getRoleList($inherit) ? : []), $or);
    }
    
    /**
     * Return the current user ID
     *
     * @return string|int|bool
     */
    public function getUserId($as = false)
    {
        /** @var User $user */
        $user = $this->getUser($as);
        
        return $user ? $user->getId() : false;
    }
    
    /**
     * Check if the needles meet the haystack using nested arrays
     * Reversing ANDs and ORs within each nested subarray
     *
     * $this->has(['dev', 'admin'], $this->getUser()->getRoles(), true); // 'dev' OR 'admin'
     * $this->has(['dev', 'admin'], $this->getUser()->getRoles(), false); // 'dev' AND 'admin'
     *
     * $this->has(['dev', 'admin'], $this->getUser()->getRoles()); // 'dev' AND 'admin'
     * $this->has([['dev', 'admin']], $this->getUser()->getRoles()); // 'dev' OR 'admin'
     * $this->has([[['dev', 'admin']]], $this->getUser()->getRoles()); // 'dev' AND 'admin'
     *
     * @param array|string|null $needles Needles to match and meet the rules
     * @param array $haystack Haystack array to search into
     * @param bool $or True to force with "OR" , false to force "AND" condition
     *
     * @return bool Return true or false if the needles rules are being met
     */
    public function has($needles = null, array $haystack = [], $or = false)
    {
        if (!is_array($needles)) {
            $needles = [$needles];
        }
        
        $result = [];
        foreach ([...$needles] as $needle) {
            if (is_array($needle)) {
                $result [] = $this->has($needle, $haystack, !$or);
            }
            else {
                $result [] = in_array($needle, $haystack, true);
            }
        }
        
        return $or ?
            !in_array(false, $result, true) :
            in_array(true, $result, true)
        ;
    }
    
    /**
     * Create a refresh a session
     *
     * @param bool $refresh
     *
     * @throws \Phalcon\Security\Exception
     */
    public function getJwt($refresh = false)
    {
        [$key, $token] = $this->getKeyToken();
        
        $key ??= $this->security->getRandom()->uuid();
        $token ??= $this->security->getRandom()->hex(512);
        
        $sessionClass = $this->getSessionClass();
        $session = $this->getSession($key, $token) ? : new $sessionClass();
        
        if ($session && $refresh) {
            $token = $this->security->getRandom()->hex(512);
        }
        
        $session->setKey($key);
        $session->setToken($session->hash($key . $token));
        $session->setDate(date('Y-m-d H:i:s'));
        $store = ['key' => $session->getKey(), 'token' => $token];
        
        ($save = $session->save()) ?
            $this->session->set($this->sessionKey, $store) :
            $this->session->remove($this->sessionKey);
        
        return [
            'saved' => $save,
            'stored' => $this->session->has($this->sessionKey),
            'refreshed' => $save && $refresh,
            'validated' => $session->checkHash($session->getToken(), $session->getKey() . $token),
            'messages' => $session ? $session->getMessages() : [],
            'jwt' => $this->getJwtToken($this->sessionKey, $store),
        ];
    }
    
    /**
     * Get basic Identity information
     *
     * @param bool $inherit
     *
     * @return array
     */
    public function getIdentity(bool $inherit = true)
    {
        $user = $this->getUser();
        $userAs = $this->getUserAs();
        
        $roleList = [];
        $groupList = [];
        $typeList = [];
        
        if ($user) {
            if ($user->rolelist) {
                foreach ($user->rolelist as $role) {
                    $roleList [$role->getIndex()] = $role;
                }
            }
            
            if ($user->grouplist) {
                foreach ($user->grouplist as $group) {
                    $groupList [$group->getIndex()] = $group;
                    
                    if ($group->rolelist) {
                        foreach ($group->rolelist as $role) {
                            $roleList [$role->getIndex()] = $role;
                        }
                    }
                }
            }
            
            if ($user->typelist) {
                foreach ($user->typelist as $type) {
                    $typeList [$type->getIndex()] = $type;
                    
                    if ($type->grouplist) {
                        foreach ($type->grouplist as $group) {
                            $groupList [$group->getIndex()] = $group;
                            
                            if ($group->rolelist) {
                                foreach ($group->rolelist as $role) {
                                    $roleList [$role->getIndex()] = $role;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Append inherit roles
        if ($inherit) {
            $roleIndexList = [];
            foreach ($roleList as $role) {
                $roleIndexList []= $role->getIndex();
            }
            
            $inheritedRoleIndexList = $this->getInheritedRoleList($roleIndexList);
            if (!empty($inheritedRoleIndexList)) {
                
                /** @var \Phalcon\Mvc\Model\Resultset $inheritedRoleEntity */
                $inheritedRoleList = $this->getRoleClass()::find([
                    'index in ({role:array})', // @todo should filter soft-deleted roles?
                    'bind' => ['role' => $inheritedRoleIndexList],
                    'bindTypes' => ['role' => Column::BIND_PARAM_STR],
                ]);
    
                /** @var Models\Role $inheritedRoleEntity */
                foreach ($inheritedRoleList as $inheritedRoleEntity) {
                    $roleList[$inheritedRoleEntity->getIndex()] = $inheritedRoleEntity;
                }
            }
        }
        
        // We don't need userAs group / type / role list
        return [
            'loggedIn' => $this->isLoggedIn(),
            'loggedInAs' => $this->isLoggedInAs(),
            'user' => $user,
            'userAs' => $userAs,
            'roleList' => $roleList,
            'typeList' => $typeList,
            'groupList' => $groupList,
        ];
    }
    
    /**
     * Return the list of inherited role list (recursively)
     *
     * @param array $roleIndexList
     *
     * @return array List of inherited role list (recursive)
     */
    public function getInheritedRoleList(array $roleIndexList = [])
    {
        $inheritedRoleList = [];
        $processedRoleIndexList = [];
        
        // While we still have role index list to process
        while (!empty($roleIndexList))
        {
            // Process role index list
            foreach ($roleIndexList as $roleIndex)
            {
                // Get inherited roles from config service
                $configRoleList = $this->config->path('permissions.roles.' . $roleIndex . '.inherit', false);
                
                if ($configRoleList) {
                    // Append inherited role to process list
                    $roleList = $configRoleList->toArray();
                    $roleIndexList = array_merge($roleIndexList, $roleList);
                    $inheritedRoleList = array_merge($inheritedRoleList, $roleList);
                }
                
                // Add role index to processed list
                $processedRoleIndexList []= $roleIndex;
            }
            
            // Keep the unprocessed role index list
            $roleIndexList = array_filter(array_unique(array_diff($roleIndexList, $processedRoleIndexList)));
        }
        
        // Return the list of inherited role list (recursively)
        return array_values(array_filter(array_unique($inheritedRoleList)));
    }
    
    /**
     * Return true if the user is currently logged in
     *
     * @param bool $as
     * @param bool $refresh
     *
     * @return bool
     */
    public function isLoggedIn($as = false, $refresh = false)
    {
        return !!$this->getUser($as, $refresh);
    }
    
    /**
     * Return true if the user is currently logged in
     *
     * @param bool $refresh
     *
     * @return bool
     */
    public function isLoggedInAs($refresh = false)
    {
        return $this->isLoggedIn(true, $refresh);
    }
    
    /**
     * Get the User related to the current session
     *
     * @return User|bool
     */
    public function getUser($as = false, $refresh = false)
    {
        $property = $as ? 'userAs' : 'user';
        
        if ($refresh) {
            $this->$property = null;
        }
        
        if (is_null($this->$property)) {
            
            $session = $this->getSession();
            
            $userClass = $this->getUserClass();
            $user = !$session ? false : $userClass::findFirstWithById([
                'RoleList',
                'GroupList.RoleList',
                'TypeList.GroupList.RoleList',
//            'GroupList.TypeList.RoleList', // @TODO do it
//            'TypeList.RoleList', // @TODO do it
            ], $as ? $session->getAsUserId() : $session->getUserId());
            
            $this->$property = $user ? $user : false;
        }
        
        return $this->$property;
    }
    
    /**
     * Get the User As related to the current session
     *
     * @return bool|User
     */
    public function getUserAs()
    {
        return $this->getUser(true);
    }
    
    /**
     * Get the "Roles" related to the current session
     *
     * @param bool $inherit
     *
     * @return array|mixed
     */
    public function getRoleList(bool $inherit = true)
    {
        return $this->getIdentity($inherit)['roleList'] ?? [];
    }
    
    /**
     * Get the "Groups" related to the current session
     *
     * @param bool $inherit
     *
     * @return array
     */
    public function getGroupList(bool $inherit = true)
    {
        return $this->getIdentity($inherit)['groupList'] ?? [];
    }
    
    /**
     * Get the "Types" related to the current session
     *
     * @param bool $inherit
     *
     * @return array
     */
    public function getTypeList(bool $inherit = true)
    {
        return $this->getIdentity($inherit)['typeList'] ?? [];
    }
    
    /**
     * @param array|null $roles
     *
     * @return array
     */
    public function getAclRoles(array $roleList = null)
    {
        $roleList ??= $this->getRoleList();
        
        $aclRoles = [];
        $aclRoles['everyone'] = new Role('everyone');
        foreach ($roleList as $role) {
            if ($role) {
                $aclRoles[$role->getIndex()] ??= new Role($role->getIndex());
            }
        }
        
        return array_values($aclRoles);
    }
    
    /**
     * @param $userId
     *
     * @return array
     */
    public function loginAs($params)
    {
        /** @var Session $session */
        $session = $this->getSession();
        
        $validation = new Validation();
        $validation->add('userId', new PresenceOf(['message' => 'userId is required']));
        $validation->validate($params);
        
        $userId = $session->getUserId();
        
        if (!empty($userId) && !empty($params['userId'])) {
            
            if ((int)$params['userId'] === (int)$userId) {
                return $this->logoutAs();
            }
            
            $userClass = $this->getUserClass();
            $asUser = $userClass::findFirstById((int)$params['userId']);
            
            if ($asUser) {
                if ($this->hasRole(['admin', 'dev'])) {
                    $session->setAsUserId($userId);
                    $session->setUserId($params['userId']);
                }
            }
            else {
                $validation->appendMessage(new Message('User Not Found', 'userId', 'PresenceOf', 404));
            }
        }
        
        $saved = $session ? $session->save() : false;
        foreach ($session->getMessages() as $message) {
            $validation->appendMessage($message);
        }
        
        return [
            'saved' => $saved,
            'loggedIn' => $this->isLoggedIn(false, true),
            'loggedInAs' => $this->isLoggedIn(true, true),
            'messages' => $validation->getMessages(),
        ];
    }
    
    /**
     * @return array
     */
    public function logoutAs()
    {
        /** @var Session $session */
        $session = $this->getSession();
        
        $asUserId = $session->getAsUserId();
        $userId = $session->getUserId();
        if (!empty($asUserId) && !empty($userId)) {
            $session->setUserId($asUserId);
            $session->setAsUserId(null);
        }
        
        return [
            'saved' => $session ? $session->save() : false,
            'loggedIn' => $this->isLoggedIn(false, true),
            'loggedInAs' => $this->isLoggedIn(true, true),
            'messages' => $session->getMessages(),
        ];
    }
    
    /**
     *
     */
    public function oauth2(string $provider, int $id, string $accessToken, ?array $meta = [])
    {
        $loggedInUser = null;
        $saved = null;
        
        // retrieve and prepare oauth2 entity
        $oauth2 = Oauth2::findFirst([
            'provider = :provider: and id = :id:',
            'bind' => [
                'provider' => $this->filter->sanitize($provider, 'string'),
                'id' => (int)$id,
            ],
            'bindTypes' => [
                'provider' => Column::BIND_PARAM_STR,
                'id' => Column::BIND_PARAM_INT,
            ],
        ]);
        if (!$oauth2) {
            $oauth2 = new Oauth2();
            $oauth2->setProviderName($provider);
            $oauth2->setProviderId($id);
        }
        $oauth2->setAccessToken($accessToken);
        $oauth2->setMeta($meta);
        $oauth2->setName($meta['name'] ?? null);
        $oauth2->setFirstName($meta['first_name'] ?? null);
        $oauth2->setLastName($meta['last_name'] ?? null);
        $oauth2->setEmail($meta['email'] ?? null);
        
        // ge the current session
        $session = $this->getSession();
        
        // link the current user to the oauth2 entity
        $oauth2UserId = $oauth2->getUserId();
        $sessionUserId = $session->getUserId();
        if (empty($oauth2UserId) && !empty($sessionUserId)) {
            $oauth2->setUserId($sessionUserId);
        }
        
        // prepare validation
        $validation = new Validation();
        
        // save the oauth2 entity
        $saved = $oauth2->save();
        
        // append oauth2 error messages
        foreach ($oauth2->getMessages() as $message) {
            $validation->appendMessage($message);
        }
        
        // a session is required
        if (!$session) {
            $validation->appendMessage(new Message('A session is required', 'session', 'PresenceOf', 403));
        }
        
        // user id is required
        $validation->add('userId', new PresenceOf(['message' => 'userId is required']));
        $validation->validate($oauth2 ? $oauth2->toArray() : []);
        
        // All validation passed
        if ($saved && !$validation->getMessages()->count()) {
            
            $user = $this->findUser($oauth2->getUserId());
            
            // user not found, login failed
            if (!$user) {
                $validation->appendMessage(new Message('Login Failed', ['id'], 'LoginFailed', 401));
            }
            
            // access forbidden, login failed
            else if ($user->isDeleted()) {
                $validation->appendMessage(new Message('Login Forbidden', 'password', 'LoginForbidden', 403));
            }
            
            // login success
            else {
                $loggedInUser = $user;
            }
            
            // Set the oauth user id into the session
            $session->setUserId($loggedInUser ? $loggedInUser->getId() : null);
            $saved = $session->save();
            
            // append session error messages
            foreach ($session->getMessages() as $message) {
                $validation->appendMessage($message);
            }
        }
        
        return [
            'saved' => $saved,
            'loggedIn' => $this->isLoggedIn(false, true),
            'loggedInAs' => $this->isLoggedIn(true, true),
            'messages' => $validation->getMessages(),
        ];
    }
    
    /**
     * Login Action
     * - Require an active session to bind the logged in userId
     *
     * @return array
     */
    public function login(array $params = null)
    {
        $loggedInUser = null;
        $saved = null;
        
        $session = $this->getSession();
        
        $validation = new Validation();
        $validation->add('email', new PresenceOf(['message' => 'email is required']));
        $validation->add('password', new PresenceOf(['message' => 'password is required']));
        $validation->validate($params);
        
        if (!$session) {
            $validation->appendMessage(new Message('A session is required', 'session', 'PresenceOf', 403));
        }
        
        $messages = $validation->getMessages();
        
        if (!$messages->count()) {
            $user = $this->findUser($params['email'] ?? $params['username']);
            
            if (!$user) {
                // user not found, login failed
                $validation->appendMessage(new Message('Login Failed', ['email', 'password'], 'LoginFailed', 401));
            }
            
            else if (empty($user->getPassword())) {
                // password disabled, login failed
                $validation->appendMessage(new Message('Password Login Disabled', 'password', 'LoginFailed', 401));
            }
            
            else if (!$user->checkPassword($params['password'])) {
                // password failed, login failed
                $validation->appendMessage(new Message('Login Failed', ['email', 'password'], 'LoginFailed', 401));
            }
            
            else if ($user->isDeleted()) {
                // access forbidden, login failed
                $validation->appendMessage(new Message('Login Forbidden', 'password', 'LoginForbidden', 403));
            }
            
            // login success
            else {
                $loggedInUser = $user;
            }
            
            $session->setUserId($loggedInUser ? $loggedInUser->getId() : null);
            $saved = $session->save();
            
            foreach ($session->getMessages() as $message) {
                $validation->appendMessage($message);
            }
        }
        
        return [
            'saved' => $saved,
            'loggedIn' => $this->isLoggedIn(false, true),
            'loggedInAs' => $this->isLoggedIn(true, true),
            'messages' => $validation->getMessages(),
        ];
    }
    
    /**
     * Log the user out from the database session
     *
     * @return bool|mixed|null
     */
    public function logout()
    {
        $saved = false;
        
        $session = $this->getSession();
        $validation = new Validation();
        $validation->validate();
        
        if (!$session) {
            $validation->appendMessage(new Message('A session is required', 'session', 'PresenceOf', 403));
        }
        else {
            // Logout
            $session->setUserId(null);
            $session->setAsUserId(null);
            $saved = $session->save();
            
            foreach ($session->getMessages() as $message) {
                $validation->appendMessage($message);
            }
        }
        
        return [
            'saved' => $saved,
            'loggedIn' => $this->isLoggedIn(false, true),
            'loggedInAs' => $this->isLoggedIn(true, true),
            'messages' => $validation->getMessages(),
        ];
    }
    
    /**
     * @param array|null $params
     *
     * @return array
     */
    public function reset(array $params = null)
    {
        $saved = false;
        $sent = false;
        $token = null;
        
        $session = $this->getSession();
        $validation = new Validation();
        
        $validation->add('email', new PresenceOf(['message' => 'email is required']));
        $validation->validate($params);
        
        if (!$session) {
            $validation->appendMessage(new Message('A session is required', 'session', 'PresenceOf', 403));
        }
        else {
            $user = false;
            if (isset($params['email'])) {
                $userClass = $this->getUserClass();
                $user = $userClass::findFirstByEmail($params['email']);
            }
            
            // Reset
            if ($user) {
                
                // Password reset request
                if (empty($params['token'])) {
                    
                    // Generate a new token
                    $token = $user->prepareToken();
                    
                    // Send it by email
                    $emailClass = $this->getEmailClass();
                    
                    $email = new $emailClass();
                    $email->setViewPath('template/email');
                    $email->setTemplateByIndex('reset-password');
                    $email->setTo([$user->getEmail()]);
                    $meta = [];
                    $meta['user'] = $user->expose(['User' => [
                        false,
                        'firstName',
                        'lastName',
                        'email',
                    ]]);
                    $meta['resetLink'] = $this->url->get('/reset-password/' . $token);
                    
                    $email->setMeta($meta);
                    $saved = $user->save();
                    $sent = $saved ? $email->send() : false;
                    
                    // Appending error messages
                    foreach (['user', 'email'] as $e) {
                        foreach ($$e->getMessages() as $message) {
                            $validation->appendMessage($message);
                        }
                    }
                }
                
                // Password reset
                else {
                    $validation->add('password', new PresenceOf(['message' => 'password is required']));
                    $validation->add('passwordConfirm', new PresenceOf(['message' => 'password confirm is required']));
                    $validation->add('password', new Confirmation(['message' => 'password does not match passwordConfirm', 'with' => 'passwordConfirm']));
                    $validation->validate($params);
                    
                    if (!$user->checkToken($params['token'])) {
                        $validation->appendMessage(new Message('invalid token', 'token', 'NotValid', 400));
                    }
                    else {
                        if (!count($validation->getMessages())) {
                            $params['token'] = null;
                            $user->assign($params, ['token', 'password', 'passwordConfirm']);
                            $saved = $user->save();
                        }
                    }
                }
                
                // Appending error messages
                foreach ($user->getMessages() as $message) {
                    $validation->appendMessage($message);
                }
            }
            else {
//                $validation->appendMessage(new Message('User not found', 'user', 'PresenceOf', 404));
                // OWASP Protect User Enumeration
                $saved = true;
                $sent = true;
            }
        }
        
        return [
            'saved' => $saved,
            'sent' => $sent,
            'messages' => $validation->getMessages(),
        ];
    }
    
    /**
     * Get key / token fields to use for the session fetch & validation
     *
     * @return array
     */
    public function getKeyToken()
    {
        $basicAuth = $this->request->getBasicAuth();
        $authorization = array_filter(explode(' ', $this->request->getHeader('Authorization') ? : ''));
        
        $jwt = $this->request->get('jwt', 'string');
        $key = $this->request->get('key', 'string');
        $token = $this->request->get('token', 'string');
        
        if (!empty($jwt)) {
            $sessionClaim = $this->getClaim($jwt, $this->sessionKey);
            $key = $sessionClaim->key ?? null;
            $token = $sessionClaim->token ?? null;
        }
        
        else if (!empty($basicAuth)) {
            $key = $basicAuth['username'] ?? null;
            $token = $basicAuth['password'] ?? null;
        }
        
        else if (!empty($authorization)) {
            $authorizationType = $authorization[0] ?? 'Bearer';
            $authorizationToken = $authorization[1] ?? null;
            
            if (strtolower($authorizationType) === 'bearer') {
                $sessionClaim = $this->getClaim($authorizationToken, $this->sessionKey);
                $key = $sessionClaim->key ?? null;
                $token = $sessionClaim->token ?? null;
            }
        }
        
        else if ($this->session->has($this->sessionKey)) {
            $sessionStore = $this->session->get($this->sessionKey);
            $key = $sessionStore['key'] ?? null;
            $token = $sessionStore['token'] ?? null;
        }
        
        return [$key, $token];
    }
    
    /**
     * Return the session by key if the token is valid
     *
     * @param string $key
     * @param string $token
     * @param bool $refresh Pass true to force a session fetch from the database
     *
     * @return void|bool|Session Return the session by key if the token is valid, false otherwise
     */
    public function getSession(string $key = null, string $token = null, bool $refresh = false)
    {
        if (!isset($key, $token)) {
            [$key, $token] = $this->getKeyToken();
        }
        
        if (empty($key) || empty($token)) {
            return false;
        }
        
        if ($refresh) {
            $this->currentSession = null;
        }
        
        if (isset($this->currentSession)) {
            return $this->currentSession;
        }
        
        $sessionClass = $this->getSessionClass();
        $session = $sessionClass::findFirstByKey($this->filter->sanitize($key, 'string'));
        
        if ($session && $session->checkHash($session->getToken(), $key . $token)) {
            $this->currentSession = $session;
        }
        
        return $this->currentSession;
    }
    
    /**
     * Get a claim
     * @TODO generate private & public keys
     *
     * @param string $token
     * @param string|null $claim
     *
     * @return array|mixed
     */
    public function getClaim(string $token, string $claim = null)
    {
        $jwt = (new Parser())->parse((string)$token);
        
        return $claim ? $jwt->getClaim($claim) : $jwt->getClaims();
    }
    
    /**
     * Generate a new JWT
     * @TODO generate private & public keys and use them
     *
     * @param $claim
     * @param $data
     *
     * @return string
     */
    public function getJwtToken($claim, $data)
    {
        $uri = $this->request->getScheme() . '://' . $this->request->getHttpHost();

//        $privateKey = new Key('file://{path to your private key}');
        $signer = new Sha512();
        $time = time();
        
        $token = (new Builder())
            ->issuedBy($uri) // Configures the issuer (iss claim)
            ->permittedFor($uri) // Configures the audience (aud claim)
            ->identifiedBy($claim, true) // Configures the id (jti claim), replicating as a header item
            ->issuedAt($time) // Configures the time that the token was issue (iat claim)
            ->canOnlyBeUsedAfter($time + 60) // Configures the time that the token can be used (nbf claim)
            ->expiresAt($time + 3600) // Configures the expiration time of the token (exp claim)
            ->withClaim($claim, $data) // Configures a new claim, called "uid"
            ->getToken($signer) // Retrieves the generated token
//            ->getToken($signer,  $privateKey); // Retrieves the generated token
        ;
        
        return (string)$token;
    }
    
    /**
     * Retrieve the user from a username or an email
     *
     * @param $usernameOrEmail
     *
     * @return mixed
     * @todo maybe move this into user model?
     *
     */
    public function findUser($idUsernameEmail)
    {
        if (empty($idUsernameEmail)) {
            return false;
        }
        
        $userClass = $this->getUserClass();
        
        if (!is_int($idUsernameEmail)) {
            $usernameEmail = $this->filter->sanitize($idUsernameEmail, ['string', 'trim']);
            $user = $userClass::findFirst([
                'email = :email: or username = :username:',
                'bind' => [
                    'email' => $usernameEmail,
                    'username' => $usernameEmail,
                ],
                'bindTypes' => [
                    'email' => Column::BIND_PARAM_STR,
                    'username' => Column::BIND_PARAM_STR,
                ],
            ]);
        }
        else {
            $user = $userClass::findFirstById((int)$idUsernameEmail);
        }
        
        return $user;
    }
}
