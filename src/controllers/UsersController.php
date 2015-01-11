<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\controllers;

use Craft;
use craft\app\enums\AuthError;
use craft\app\enums\ElementType;
use craft\app\enums\LogLevel;
use craft\app\enums\UserStatus;
use craft\app\errors\Exception;
use craft\app\errors\HttpException;
use craft\app\events\UserEvent;
use craft\app\helpers\AssetsHelper;
use craft\app\helpers\IOHelper;
use craft\app\helpers\JsonHelper;
use craft\app\helpers\UrlHelper;
use craft\app\models\User as UserModel;
use craft\app\services\Users;
use craft\app\web\UploadedFile;

/**
 * The UsersController class is a controller that handles various user account related tasks such as logging-in,
 * impersonating a user, logging out, forgetting passwords, setting passwords, validating accounts, activating
 * accounts, creating users, saving users, processing user avatars, deleting, suspending and un-suspending users.
 *
 * Note that all actions in the controller, except [[actionLogin]], [[actionLogout]], [[actionGetRemainingSessionTime]],
 * [[actionSendPasswordResetEmail]], [[actionSetPassword]], [[actionVerifyEmail]] and [[actionSaveUser]] require an
 * authenticated Craft session via [[BaseController::allowAnonymous]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class UsersController extends BaseController
{
	// Properties
	// =========================================================================

	/**
	 * If set to false, you are required to be logged in to execute any of the given controller's actions.
	 *
	 * If set to true, anonymous access is allowed for all of the given controller's actions.
	 *
	 * If the value is an array of action names, then you must be logged in for any action method except for the ones in
	 * the array list.
	 *
	 * If you have a controller that where the majority of action methods will be anonymous, but you only want require
	 * login on a few, it's best to call [[requireLogin()]] in the individual methods.
	 *
	 * @var bool
	 */
	protected $allowAnonymous = ['actionLogin', 'actionLogout', 'actionGetRemainingSessionTime', 'actionSendPasswordResetEmail', 'actionSendActivationEmail', 'actionSaveUser', 'actionSetPassword', 'actionVerifyEmail'];

	// Public Methods
	// =========================================================================

	/**
	 * Displays the login template, and handles login post requests.
	 *
	 * @return null
	 */
	public function actionLogin()
	{
		$this->requirePostRequest();

		if (!Craft::$app->getUser()->getIsGuest())
		{
			// Too easy.
			$this->_handleSuccessfulLogin(false);
		}

		// First, a little house-cleaning for expired, pending users.
		Craft::$app->users->purgeExpiredPendingUsers();

		$loginName = Craft::$app->request->getPost('loginName');
		$password = Craft::$app->request->getPost('password');
		$rememberMe = (bool) Craft::$app->request->getPost('rememberMe');

		// Does a user exist with that username/email?
		$user = Craft::$app->users->getUserByUsernameOrEmail($loginName);

		if (!$user)
		{
			$this->_handleInvalidLogin(AuthError::UsernameInvalid);
			return;
		}

		// Did they submit a valid password, and is the user capable of being logged-in?
		if (!$user->authenticate($password))
		{
			$this->_handleInvalidLogin($user->authError, $user);
			return;
		}

		// Log them in
		$duration = Craft::$app->config->getUserSessionDuration($rememberMe);

		if (Craft::$app->getUser()->login($user, $duration))
		{
			$this->_handleSuccessfulLogin(true);
		}
		else
		{
			// Unknown error
			$this->_handleInvalidLogin(null, $user)
			return;
		}
	}

	/**
	 * Logs a user in for impersonation.  Requires you to be an administrator.
	 *
	 * @return null
	 */
	public function actionImpersonate()
	{
		$this->requireLogin();
		$this->requireAdmin();
		$this->requirePostRequest();

		$userId = Craft::$app->request->getPost('userId');

		if (Craft::$app->getUser()->loginByUserId($userId))
		{
			Craft::$app->getSession()->setNotice(Craft::t('Logged in.'));

			$this->_handleSuccessfulLogin(true);
		}
		else
		{
			Craft::$app->getSession()->setError(Craft::t('There was a problem impersonating this user.'));
			Craft::log(Craft::$app->getUser()->getIdentity()->username.' tried to impersonate userId: '.$userId.' but something went wrong.', LogLevel::Error);
		}
	}

	/**
	 * Returns how many seconds are left in the current user session.
	 *
	 * @return null
	 */
	public function actionGetRemainingSessionTime()
	{
		echo Craft::$app->getUser()->getRemainingSessionTime();
		Craft::$app->end();
	}

	/**
	 * @return null
	 */
	public function actionLogout()
	{
		Craft::$app->getUser()->logout(false);

		if (Craft::$app->request->isAjaxRequest())
		{
			$this->returnJson([
				'success' => true
			]);
		}
		else
		{
			$this->redirect('');
		}
	}

	/**
	 * Sends a password reset email.
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function actionSendPasswordResetEmail()
	{
		$this->requirePostRequest();

		$errors = [];

		// If someone's logged in and they're allowed to edit other users, then see if a userId was submitted
		if (Craft::$app->getUser()->checkPermission('editUsers'))
		{
			$userId = Craft::$app->request->getPost('userId');

			if ($userId)
			{
				$user = Craft::$app->users->getUserById($userId);

				if (!$user)
				{
					throw new HttpException(404);
				}
			}
		}

		if (!isset($user))
		{
			$loginName = Craft::$app->request->getPost('loginName');

			if (!$loginName)
			{
				$errors[] = Craft::t('Username or email is required.');
			}
			else
			{
				$user = Craft::$app->users->getUserByUsernameOrEmail($loginName);

				if (!$user)
				{
					$errors[] = Craft::t('Invalid username or email.');
				}
			}
		}

		if (!empty($user))
		{
			if (Craft::$app->users->sendPasswordResetEmail($user))
			{
				if (Craft::$app->request->isAjaxRequest())
				{
					$this->returnJson(['success' => true]);
				}
				else
				{
					Craft::$app->getSession()->setNotice(Craft::t('Password reset email sent.'));
					$this->redirectToPostedUrl();
				}
			}

			$errors[] = Craft::t('There was a problem sending the password reset email.');
		}

		if (Craft::$app->request->isAjaxRequest())
		{
			$this->returnErrorJson($errors);
		}
		else
		{
			// Send the data back to the template
			Craft::$app->urlManager->setRouteVariables([
				'errors'    => $errors,
				'loginName' => isset($loginName) ? $loginName : null,
			]);
		}
	}

	/**
	 * Generates a new verification code for a given user, and returns its URL.
	 *
	 * @throws HttpException|Exception
	 * @return null
	 */
	public function actionGetPasswordResetUrl()
	{
		$this->requireAdmin();

		if (!$this->_verifyExistingPassword())
		{
			throw new HttpException(403);
		}

		$userId = Craft::$app->request->getRequiredParam('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		echo Craft::$app->users->getPasswordResetUrl($user);
		Craft::$app->end();
	}

	/**
	 * Sets a user's password once they've verified they have access to their email.
	 *
	 * @throws HttpException|Exception
	 * @return null
	 */
	public function actionSetPassword()
	{
		// Have they just submitted a password, or are we just displaying teh page?
		if (!Craft::$app->request->isPostRequest())
		{
			if ($info = $this->_processTokenRequest())
			{
				$userToProcess = $info['userToProcess'];
				$id = $info['id'];
				$code = $info['code'];

				Craft::$app->getUser()->sendUsernameCookie($userToProcess);

				// Send them to the set password template.
				$url = Craft::$app->config->getSetPasswordPath($code, $id, $userToProcess);

				$this->_processSetPasswordPath($userToProcess);

				$this->renderTemplate($url, [
					'code'    => $code,
					'id'      => $id,
					'newUser' => ($userToProcess->password ? false : true),
				]);
			}
		}
		else
		{
			// POST request. They've just set the password.
			$code          = Craft::$app->request->getRequiredPost('code');
			$id            = Craft::$app->request->getRequiredParam('id');
			$userToProcess = Craft::$app->users->getUserByUid($id);

			$url = Craft::$app->config->getSetPasswordPath($code, $id, $userToProcess);

			// See if we still have a valid token.
			$isCodeValid = Craft::$app->users->isVerificationCodeValidForUser($userToProcess, $code);

			if (!$userToProcess || !$isCodeValid)
			{
				$this->_processInvalidToken($userToProcess);
			}

			$newPassword = Craft::$app->request->getRequiredPost('newPassword');
			$userToProcess->newPassword = $newPassword;

			if ($userToProcess->passwordResetRequired)
			{
				$forceDifferentPassword = true;
			}
			else
			{
				$forceDifferentPassword = false;
			}

			if (Craft::$app->users->changePassword($userToProcess, $forceDifferentPassword))
			{
				if ($userToProcess->status == UserStatus::Pending)
				{
					// Activate them
					Craft::$app->users->activateUser($userToProcess);

					// Treat this as an activation request
					$this->_onAfterActivateUser($userToProcess);
				}

				// Can they access the CP?
				if ($userToProcess->can('accessCp'))
				{
					// Send them to the login page
					$url = Craft::$app->config->getLoginPath();
				}
				else
				{
					// Send them to the 'setPasswordSuccessPath'.
					$setPasswordSuccessPath = Craft::$app->config->getLocalized('setPasswordSuccessPath');
					$url = UrlHelper::getSiteUrl($setPasswordSuccessPath);
				}

				$this->redirect($url);
			}

			Craft::$app->getSession()->setNotice(Craft::t('Couldn’t update password.'));

			$this->_processSetPasswordPath($userToProcess);

			$errors = [];
			$errors = array_merge($errors, $userToProcess->getErrors('newPassword'));

			$this->renderTemplate($url, [
				'errors' => $errors,
				'code' => $code,
				'id' => $id,
				'newUser' => ($userToProcess->password ? false : true),
			]);
		}
	}

	/**
	 * Verifies that a user has access to an email address.
	 *
	 * @return null;
	 */
	public function actionVerifyEmail()
	{
		if ($info = $this->_processTokenRequest())
		{
			$userToProcess = $info['userToProcess'];
			$userIsPending = $userToProcess->status == UserStatus::Pending;

			Craft::$app->users->verifyEmailForUser($userToProcess);

			if ($userIsPending)
			{
				// They were just activated, so treat this as an activation request
				$this->_onAfterActivateUser($userToProcess);
			}

			// Redirect to the site/CP root
			$url = UrlHelper::getUrl('');
			$this->redirect($url);
		}
	}

	/**
	 * Manually activates a user account.  Only admins have access.
	 *
	 * @return null
	 */
	public function actionActivateUser()
	{
		$this->requireAdmin();
		$this->requirePostRequest();

		$userId = Craft::$app->request->getRequiredPost('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		if (Craft::$app->users->activateUser($user))
		{
			Craft::$app->getSession()->setNotice(Craft::t('Successfully activated the user.'));
		}
		else
		{
			Craft::$app->getSession()->setError(Craft::t('There was a problem activating the user.'));
		}

		$this->redirectToPostedUrl();
	}

	/**
	 * Edit a user account.
	 *
	 * @param array       $variables
	 * @param string|null $account
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function actionEditUser(array $variables = [], $account = null)
	{
		// Determine which user account we're editing
		// ---------------------------------------------------------------------

		$isClientAccount = false;

		// This will be set if there was a validation error.
		if (empty($variables['account']))
		{
			// Are we editing a specific user account?
			if ($account !== null)
			{
				switch ($account)
				{
					case 'current':
					{
						$variables['account'] = Craft::$app->getUser()->getIdentity();

						break;
					}
					case 'client':
					{
						$isClientAccount = true;
						$variables['account'] = Craft::$app->users->getClient();

						if (!$variables['account'])
						{
							// Registering the Client
							$variables['account'] = new UserModel();
							$variables['account']->client = true;
						}

						break;
					}
					default:
					{
						throw new HttpException(404);
					}
				}
			}
			else if (!empty($variables['userId']))
			{
				$variables['account'] = Craft::$app->users->getUserById($variables['userId']);

				if (!$variables['account'])
				{
					throw new HttpException(404);
				}
			}
			else if (Craft::$app->getEdition() == Craft::Pro)
			{
				// Registering a new user
				$variables['account'] = new UserModel();
			}
			else
			{
				// Nada.
				throw new HttpException(404);
			}
		}

		$variables['isNewAccount'] = !$variables['account']->id;

		// Make sure they have permission to edit this user
		// ---------------------------------------------------------------------

		if (!$variables['account']->isCurrent())
		{
			if ($variables['isNewAccount'])
			{
				$this->requirePermission('registerUsers');
			}
			else
			{
				$this->requirePermission('editUsers');
			}
		}

		// Determine which actions should be available
		// ---------------------------------------------------------------------

		$statusActions  = [];
		$sketchyActions = [];

		if (Craft::$app->getEdition() >= Craft::Client && !$variables['isNewAccount'])
		{
			switch ($variables['account']->getStatus())
			{
				case UserStatus::Pending:
				{
					$variables['statusLabel'] = Craft::t('Unverified');

					$statusActions[] = ['action' => 'users/sendActivationEmail', 'label' => Craft::t('Send activation email')];

					if (Craft::$app->getUser()->getIsAdmin())
					{
						$statusActions[] = ['id' => 'copy-passwordreset-url', 'label' => Craft::t('Copy activation URL')];
						$statusActions[] = ['action' => 'users/activateUser', 'label' => Craft::t('Activate account')];
					}

					break;
				}
				case UserStatus::Locked:
				{
					$variables['statusLabel'] = Craft::t('Locked');

					if (Craft::$app->getUser()->checkPermission('administrateUsers'))
					{
						$statusActions[] = ['action' => 'users/unlockUser', 'label' => Craft::t('Unlock')];
					}

					break;
				}
				case UserStatus::Suspended:
				{
					$variables['statusLabel'] = Craft::t('Suspended');

					if (Craft::$app->getUser()->checkPermission('administrateUsers'))
					{
						$statusActions[] = ['action' => 'users/unsuspendUser', 'label' => Craft::t('Unsuspend')];
					}

					break;
				}
				case UserStatus::Active:
				{
					$variables['statusLabel'] = Craft::t('Active');

					if (!$variables['account']->isCurrent())
					{
						$statusActions[] = ['action' => 'users/sendPasswordResetEmail', 'label' => Craft::t('Send password reset email')];

						if (Craft::$app->getUser()->getIsAdmin())
						{
							$statusActions[] = ['id' => 'copy-passwordreset-url', 'label' => Craft::t('Copy password reset URL')];
						}
					}

					break;
				}
			}

			if (!$variables['account']->isCurrent())
			{
				if (Craft::$app->getUser()->checkPermission('administrateUsers') && $variables['account']->getStatus() != UserStatus::Suspended)
				{
					$sketchyActions[] = ['action' => 'users/suspendUser', 'label' => Craft::t('Suspend')];
				}

				if (Craft::$app->getUser()->checkPermission('deleteUsers'))
				{
					$sketchyActions[] = ['id' => 'delete-btn', 'label' => Craft::t('Delete…')];
				}
			}
		}

		$variables['actions'] = [];

		if ($statusActions)
		{
			array_push($variables['actions'], $statusActions);
		}

		// Give plugins a chance to add more actions
		$pluginActions = Craft::$app->plugins->call('addUserAdministrationOptions', [$variables['account']], true);

		if ($pluginActions)
		{
			$variables['actions'] = array_merge($variables['actions'], array_values($pluginActions));
		}

		if ($sketchyActions)
		{
			array_push($variables['actions'], $sketchyActions);
		}

		// Set the appropriate page title
		// ---------------------------------------------------------------------

		if (!$variables['isNewAccount'])
		{
			if ($variables['account']->isCurrent())
			{
				$variables['title'] = Craft::t('My Account');
			}
			else
			{
				$variables['title'] = Craft::t("{user}’s Account", ['user' => $variables['account']->name]);
			}
		}
		else if ($isClientAccount)
		{
			$variables['title'] = Craft::t('Register the client’s account');
		}
		else
		{
			$variables['title'] = Craft::t("Register a new user");
		}

		// Show tabs if they have Craft Pro
		// ---------------------------------------------------------------------

		if (Craft::$app->getEdition() == Craft::Pro)
		{
			$variables['selectedTab'] = 'account';

			$variables['tabs'] = [
				'account' => [
					'label' => Craft::t('Account'),
					'url'   => '#account',
				]
			];

			// No need to show the Profile tab if it's a new user (can't have an avatar yet) and there's no user fields.
			if (!$variables['isNewAccount'] || $variables['account']->getFieldLayout()->getFields())
			{
				$variables['tabs']['profile'] = [
					'label' => Craft::t('Profile'),
					'url'   => '#profile',
				];
			}

			// If they can assign user groups and permissions, show the Permissions tab
			if (Craft::$app->getUser()->getIdentity()->can('assignUserPermissions'))
			{
				$variables['tabs']['perms'] = [
					'label' => Craft::t('Permissions'),
					'url'   => '#perms',
				];
			}
		}
		else
		{
			$variables['tabs'] = [];
		}

		// Ugly.  But Users don't have a real fieldlayout/tabs.
		$accountFields = ['username', 'firstName', 'lastName', 'email', 'password', 'newPassword', 'currentPassword', 'passwordResetRequired', 'preferredLocale'];

		if (Craft::$app->getEdition() == Craft::Pro && $variables['account']->hasErrors())
		{
			$errors = $variables['account']->getErrors();

			foreach ($errors as $attribute => $error)
			{
				if (in_array($attribute, $accountFields))
				{
					$variables['tabs']['account']['class'] = 'error';
				}
				else if (isset($variables['tabs']['profile']))
				{
					$variables['tabs']['profile']['class'] = 'error';
				}
			}
		}

		// Load the resources and render the page
		// ---------------------------------------------------------------------

		Craft::$app->templates->includeCssResource('css/account.css');
		Craft::$app->templates->includeJsResource('js/AccountSettingsForm.js');
		Craft::$app->templates->includeJs('new Craft.AccountSettingsForm('.JsonHelper::encode($variables['account']->id).', '.($variables['account']->isCurrent() ? 'true' : 'false').');');

		Craft::$app->templates->includeTranslations(
			'Please enter your current password.',
			'Please enter your password.'
		);

		$this->renderTemplate('users/_edit', $variables);
	}

	/**
	 * Provides an endpoint for saving a user account.
	 *
	 * This action accounts for the following scenarios:
	 *
	 * - An admin registering a new user account.
	 * - An admin editing an existing user account.
	 * - A normal user with user-administration permissions registering a new user account.
	 * - A normal user with user-administration permissions editing an existing user account.
	 * - A guest registering a new user account ("public registration").
	 *
	 * This action behaves the same regardless of whether it was requested from the Control Panel or the front-end site.
	 *
	 * @throws HttpException|Exception
	 * @return null
	 */
	public function actionSaveUser()
	{
		$this->requirePostRequest();

		$currentUser = Craft::$app->getUser()->getIdentity();
		$requireEmailVerification = Craft::$app->systemSettings->getSetting('users', 'requireEmailVerification');

		// Get the user being edited
		// ---------------------------------------------------------------------

		$userId = Craft::$app->request->getPost('userId');
		$isNewUser = !$userId;
		$thisIsPublicRegistration = false;

		// Are we editing an existing user?
		if ($userId)
		{
			$user = Craft::$app->users->getUserById($userId);

			if (!$user)
			{
				throw new Exception(Craft::t('No user exists with the ID “{id}”.', ['id' => $userId]));
			}

			if (!$user->isCurrent())
			{
				// Make sure they have permission to edit other users
				$this->requirePermission('editUsers');
			}
		}
		else if (Craft::$app->getEdition() == Craft::Client)
		{
			// Make sure they're logged in
			$this->requireAdmin();

			// Make sure there's no Client user yet
			if (Craft::$app->users->getClient())
			{
				throw new Exception(Craft::t('A client account already exists.'));
			}

			$user = new UserModel();
			$user->client = true;
		}
		else
		{
			// Make sure this is Craft Pro, since that's required for having multiple user accounts
			Craft::$app->requireEdition(Craft::Pro);

			// Is someone logged in?
			if ($currentUser)
			{
				// Make sure they have permission to register users
				$this->requirePermission('registerUsers');
			}
			else
			{
				// Make sure public registration is allowed
				if (!Craft::$app->systemSettings->getSetting('users', 'allowPublicRegistration'))
				{
					throw new HttpException(403);
				}

				$thisIsPublicRegistration = true;
			}

			$user = new UserModel();
		}

		// Handle secure properties (email and password)
		// ---------------------------------------------------------------------

		$verifyNewEmail = false;

		// Are they allowed to set the email address?
		if ($isNewUser || $user->isCurrent() || $currentUser->can('changeUserEmails'))
		{
			$newEmail = Craft::$app->request->getPost('email');

			// Did it just change?
			if ($newEmail && $newEmail != $user->email)
			{
				// Does that email need to be verified?
				if ($requireEmailVerification && (!$currentUser->admin || Craft::$app->request->getPost('sendVerificationEmail')))
				{
					// Save it as an unverified email for now
					$user->unverifiedEmail = $newEmail;
					$verifyNewEmail = true;

					if ($isNewUser)
					{
						$user->email = $newEmail;
					}
				}
				else
				{
					// We trust them
					$user->email = $newEmail;
				}
			}
		}

		// Are they allowed to set a new password?
		if ($thisIsPublicRegistration)
		{
			$user->newPassword = Craft::$app->request->getPost('password');
		}
		else if ($user->isCurrent())
		{
			$user->newPassword = Craft::$app->request->getPost('newPassword');
		}

		// If editing an existing user and either of these properties are being changed,
		// require the user's current password for additional security
		if (!$isNewUser && ($newEmail || $user->newPassword))
		{
			if (!$this->_verifyExistingPassword())
			{
				Craft::log('Tried to change the email or password for userId: '.$user->id.', but the current password does not match what the user supplied.', LogLevel::Warning);
				$user->addError('currentPassword', Craft::t('Incorrect current password.'));
			}
		}

		// Handle the rest of the user properties
		// ---------------------------------------------------------------------

		// Is the site set to use email addresses as usernames?
		if (Craft::$app->config->get('useEmailAsUsername'))
		{
			$user->username    =  $user->email;
		}
		else
		{
			$user->username    = Craft::$app->request->getPost('username', ($user->username ? $user->username : $user->email));
		}

		$user->firstName       = Craft::$app->request->getPost('firstName', $user->firstName);
		$user->lastName        = Craft::$app->request->getPost('lastName', $user->lastName);
		$user->preferredLocale = Craft::$app->request->getPost('preferredLocale', $user->preferredLocale);
		$user->weekStartDay    = Craft::$app->request->getPost('weekStartDay', $user->weekStartDay);

		// If email verification is required, then new users will be saved in a pending state,
		// even if an admin is doing this and opted to not send the verification email
		if ($isNewUser && $requireEmailVerification)
		{
			$user->pending = true;
		}

		// There are some things only admins can change
		if ($currentUser->admin)
		{
			$user->passwordResetRequired = (bool) Craft::$app->request->getPost('passwordResetRequired', $user->passwordResetRequired);
			$user->admin                 = (bool) Craft::$app->request->getPost('admin', $user->admin);
		}

		// If this is Craft Pro, grab any profile content from post
		if (Craft::$app->getEdition() == Craft::Pro)
		{
			$user->setContentFromPost('fields');
		}

		// Validate and save!
		// ---------------------------------------------------------------------

		if (Craft::$app->users->saveUser($user))
		{
			// Save the user's photo, if it was submitted
			$this->_processUserPhoto($user);

			// If this is public registration, assign the user to the default user group
			if ($thisIsPublicRegistration)
			{
				// Assign them to the default user group
				Craft::$app->userGroups->assignUserToDefaultGroup($user);
			}
			else
			{
				// Assign user groups and permissions if the current user is allowed to do that
				$this->_processUserGroupsPermissions($user);
			}

			// Do we need to send a verification email out?
			if ($verifyNewEmail)
			{
				// Temporarily set the unverified email on the UserModel so the verification email goes to the
				// right place
				$originalEmail = $user->email;
				$user->email = $user->unverifiedEmail;

				try
				{
					if ($isNewUser)
					{
						// Send the activation email
						Craft::$app->users->sendActivationEmail($user);
					}
					else
					{
						// Send the standard verification email
						Craft::$app->users->sendNewEmailVerifyEmail($user);
					}
				}
				catch (\phpmailerException $e)
				{
					Craft::$app->getSession()->setError(Craft::t('User saved, but couldn’t send verification email. Check your email settings.'));
				}

				// Put the original email back into place
				$user->email = $originalEmail;
			}

			Craft::$app->getSession()->setNotice(Craft::t('User saved.'));

			// Is this public registration, and is the user going to be activated automatically?
			if ($thisIsPublicRegistration && $user->status == UserStatus::Active)
			{
				// Do we need to auto-login?
				if (Craft::$app->config->get('autoLoginAfterAccountActivation') === true)
				{
					Craft::$app->getUser()->loginByUserId($user->id, false, true);
				}
			}

			$this->redirectToPostedUrl($user);
		}
		else
		{
			Craft::$app->getSession()->setError(Craft::t('Couldn’t save user.'));
		}

		// Send the account back to the template
		Craft::$app->urlManager->setRouteVariables([
			'account' => $user
		]);
	}

	/**
	 * Upload a user photo.
	 *
	 * @return null
	 */
	public function actionUploadUserPhoto()
	{
		$this->requireAjaxRequest();
		$this->requireLogin();
		$userId = Craft::$app->request->getRequiredPost('userId');

		if ($userId != Craft::$app->getUser()->getIdentity()->id)
		{
			$this->requirePermission('editUsers');
		}

		// Upload the file and drop it in the temporary folder
		$file = $_FILES['image-upload'];

		try
		{
			// Make sure a file was uploaded
			if (!empty($file['name']) && !empty($file['size'])  )
			{
				$user = Craft::$app->users->getUserById($userId);
				$userName = AssetsHelper::cleanAssetName($user->username);

				$folderPath = Craft::$app->path->getTempUploadsPath().'userphotos/'.$userName.'/';

				IOHelper::clearFolder($folderPath);

				IOHelper::ensureFolderExists($folderPath);
				$fileName = AssetsHelper::cleanAssetName($file['name']);

				move_uploaded_file($file['tmp_name'], $folderPath.$fileName);

				// Test if we will be able to perform image actions on this image
				if (!Craft::$app->images->checkMemoryForImage($folderPath.$fileName))
				{
					IOHelper::deleteFile($folderPath.$fileName);
					$this->returnErrorJson(Craft::t('The uploaded image is too large'));
				}

				Craft::$app->images->cleanImage($folderPath.$fileName);

				$constraint = 500;
				list ($width, $height) = getimagesize($folderPath.$fileName);

				// If the file is in the format badscript.php.gif perhaps.
				if ($width && $height)
				{
					// Never scale up the images, so make the scaling factor always <= 1
					$factor = min($constraint / $width, $constraint / $height, 1);

					$html = Craft::$app->templates->render('_components/tools/cropper_modal',
						[
							'imageUrl' => UrlHelper::getResourceUrl('userphotos/temp/'.$userName.'/'.$fileName),
							'width' => round($width * $factor),
							'height' => round($height * $factor),
							'factor' => $factor,
							'constraint' => $constraint
						]
					);

					$this->returnJson(['html' => $html]);
				}
			}
		}
		catch (Exception $exception)
		{
			Craft::log('There was an error uploading the photo: '.$exception->getMessage(), LogLevel::Error);
		}

		$this->returnErrorJson(Craft::t('There was an error uploading your photo.'));
	}

	/**
	 * Crop user photo.
	 *
	 * @return null
	 */
	public function actionCropUserPhoto()
	{
		$this->requireAjaxRequest();
		$this->requireLogin();

		$userId = Craft::$app->request->getRequiredPost('userId');

		if ($userId != Craft::$app->getUser()->getIdentity()->id)
		{
			$this->requirePermission('editUsers');
		}

		try
		{
			$x1 = Craft::$app->request->getRequiredPost('x1');
			$x2 = Craft::$app->request->getRequiredPost('x2');
			$y1 = Craft::$app->request->getRequiredPost('y1');
			$y2 = Craft::$app->request->getRequiredPost('y2');
			$source = Craft::$app->request->getRequiredPost('source');

			// Strip off any querystring info, if any.
			if (($qIndex = mb_strpos($source, '?')) !== false)
			{
				$source = mb_substr($source, 0, mb_strpos($source, '?'));
			}

			$user = Craft::$app->users->getUserById($userId);
			$userName = AssetsHelper::cleanAssetName($user->username);

			// make sure that this is this user's file
			$imagePath = Craft::$app->path->getTempUploadsPath().'userphotos/'.$userName.'/'.$source;

			if (IOHelper::fileExists($imagePath) && Craft::$app->images->checkMemoryForImage($imagePath))
			{
				Craft::$app->users->deleteUserPhoto($user);

				$image = Craft::$app->images->loadImage($imagePath);
				$image->crop($x1, $x2, $y1, $y2);

				if (Craft::$app->users->saveUserPhoto(IOHelper::getFileName($imagePath), $image, $user))
				{
					IOHelper::clearFolder(Craft::$app->path->getTempUploadsPath().'userphotos/'.$userName);

					$html = Craft::$app->templates->render('users/_userphoto',
						[
							'account' => $user
						]
					);

					$this->returnJson(['html' => $html]);
				}
			}

			IOHelper::clearFolder(Craft::$app->path->getTempUploadsPath().'userphotos/'.$userName);
		}
		catch (Exception $exception)
		{
			$this->returnErrorJson($exception->getMessage());
		}

		$this->returnErrorJson(Craft::t('Something went wrong when processing the photo.'));
	}

	/**
	 * Delete all the photos for current user.
	 *
	 * @return null
	 */
	public function actionDeleteUserPhoto()
	{
		$this->requireAjaxRequest();
		$this->requireLogin();
		$userId = Craft::$app->request->getRequiredPost('userId');

		if ($userId != Craft::$app->getUser()->getIdentity()->id)
		{
			$this->requirePermission('editUsers');
		}

		$user = Craft::$app->users->getUserById($userId);
		Craft::$app->users->deleteUserPhoto($user);

		$user->photo = null;
		Craft::$app->users->saveUser($user);

		$html = Craft::$app->templates->render('users/_userphoto',
			[
				'account' => $user
			]
		);

		$this->returnJson(['html' => $html]);
	}

	/**
	 * Sends a new activation email to a user.
	 *
	 * @return null
	 */
	public function actionSendActivationEmail()
	{
		$this->requirePostRequest();

		$userId = Craft::$app->request->getRequiredPost('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		Craft::$app->users->sendActivationEmail($user);

		if (Craft::$app->request->isAjaxRequest())
		{
			die('great!');
		}
		else
		{
			Craft::$app->getSession()->setNotice(Craft::t('Activation email sent.'));
			$this->redirectToPostedUrl();
		}
	}

	/**
	 * Unlocks a user, bypassing the cooldown phase.
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function actionUnlockUser()
	{
		$this->requirePostRequest();
		$this->requireLogin();
		$this->requirePermission('administrateUsers');

		$userId = Craft::$app->request->getRequiredPost('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		// Even if you have administrateUsers permissions, only and admin should be able to unlock another admin.
		$currentUser = Craft::$app->getUser()->getIdentity();

		if ($user->admin && !$currentUser->admin)
		{
			throw new HttpException(403);
		}

		Craft::$app->users->unlockUser($user);

		Craft::$app->getSession()->setNotice(Craft::t('User activated.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Suspends a user.
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function actionSuspendUser()
	{
		$this->requirePostRequest();
		$this->requireLogin();
		$this->requirePermission('administrateUsers');

		$userId = Craft::$app->request->getRequiredPost('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		// Even if you have administrateUsers permissions, only and admin should be able to suspend another admin.
		$currentUser = Craft::$app->getUser()->getIdentity();

		if ($user->admin && !$currentUser->admin)
		{
			throw new HttpException(403);
		}

		Craft::$app->users->suspendUser($user);

		Craft::$app->getSession()->setNotice(Craft::t('User suspended.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Deletes a user.
	 *
	 * @throws Exception
	 * @throws HttpException
	 * @throws \CDbException
	 * @throws \Exception
	 * @return null
	 */
	public function actionDeleteUser()
	{
		$this->requirePostRequest();
		$this->requireLogin();

		$this->requirePermission('deleteUsers');

		$userId = Craft::$app->request->getRequiredPost('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		// Even if you have deleteUser permissions, only and admin should be able to delete another admin.
		if ($user->admin)
		{
			$this->requireAdmin();
		}

		// Are we transfering the user's content to a different user?
		$transferContentToId = Craft::$app->request->getPost('transferContentTo');

		if (is_array($transferContentToId) && isset($transferContentToId[0]))
		{
			$transferContentToId = $transferContentToId[0];
		}

		if ($transferContentToId)
		{
			$transferContentTo = Craft::$app->users->getUserById($transferContentToId);

			if (!$transferContentTo)
			{
				$this->_noUserExists($transferContentToId);
			}
		}
		else
		{
			$transferContentTo = null;
		}

		// Delete the user
		if (Craft::$app->users->deleteUser($user, $transferContentTo))
		{
			Craft::$app->getSession()->setNotice(Craft::t('User deleted.'));
			$this->redirectToPostedUrl();
		}
		else
		{
			Craft::$app->getSession()->setError(Craft::t('Couldn’t delete the user.'));
		}
	}

	/**
	 * Unsuspends a user.
	 *
	 * @throws HttpException
	 * @return null
	 */
	public function actionUnsuspendUser()
	{
		$this->requirePostRequest();
		$this->requireLogin();
		$this->requirePermission('administrateUsers');

		$userId = Craft::$app->request->getRequiredPost('userId');
		$user = Craft::$app->users->getUserById($userId);

		if (!$user)
		{
			$this->_noUserExists($userId);
		}

		// Even if you have administrateUsers permissions, only and admin should be able to un-suspend another admin.
		$currentUser = Craft::$app->getUser()->getIdentity();

		if ($user->admin && !$currentUser->admin)
		{
			throw new HttpException(403);
		}

		Craft::$app->users->unsuspendUser($user);

		Craft::$app->getSession()->setNotice(Craft::t('User unsuspended.'));
		$this->redirectToPostedUrl();
	}

	/**
	 * Saves the asset field layout.
	 *
	 * @return null
	 */
	public function actionSaveFieldLayout()
	{
		$this->requirePostRequest();
		$this->requireAdmin();

		// Set the field layout
		$fieldLayout = Craft::$app->fields->assembleLayoutFromPost();
		$fieldLayout->type = ElementType::User;
		Craft::$app->fields->deleteLayoutsByType(ElementType::User);

		if (Craft::$app->fields->saveLayout($fieldLayout))
		{
			Craft::$app->getSession()->setNotice(Craft::t('User fields saved.'));
			$this->redirectToPostedUrl();
		}
		else
		{
			Craft::$app->getSession()->setError(Craft::t('Couldn’t save user fields.'));
		}
	}

	/**
	 * Verifies a password for a user.
	 *
	 * @return bool
	 */
	public function actionVerifyPassword()
	{
		$this->requireAjaxRequest();

		if ($this->_verifyExistingPassword())
		{
			$this->returnJson(['success' => true]);
		}

		$this->returnErrorJson(Craft::t('Invalid password.'));
	}

	// Private Methods
	// =========================================================================

	/**
	 * Handles an invalid login attempt.
	 *
	 * @param string|null $authError
	 * @param UserModel|null   $user
	 *
	 * @return null
	 */
	private function _handleInvalidLogin($authError = null, UserModel $user = null)
	{
		switch ($authError)
		{
			case AuthError::InvalidCredentials:
			{
				$message = Craft::t('Invalid username or password.');
				break;
			}
			case AuthError::PendingVerification:
			{
				$message = Craft::t('Account has not been activated.');
				break;
			}
			case AuthError::AccountLocked:
			{
				$message = Craft::t('Account locked.');
				break;
			}
			case AuthError::AccountCooldown:
			{
				$timeRemaining = $user->getRemainingCooldownTime();

				if ($timeRemaining)
				{
					$message = Craft::t('Account locked. Try again in {time}.', ['time' => $timeRemaining->humanDuration()]);
				}
				else
				{
					$message = Craft::t('Account locked.');
				}

				break;
			}
			case AuthError::PasswordResetRequired:
			{
				$message = Craft::t('You need to reset your password. Check your email for instructions.');
				Craft::$app->users->sendPasswordResetEmail($user);
				break;
			}
			case AuthError::AccountSuspended:
			{
				$message = Craft::t('Account suspended.');
				break;
			}
			case AuthError::NoCpAccess:
			{
				$message = Craft::t('You cannot access the CP with that account.');
				break;
			}
			case AuthError::NoCpOfflineAccess:
			{
				$message = Craft::t('You cannot access the CP while the system is offline with that account.');
				break;
			}
		}

		if (Craft::$app->request->isAjaxRequest())
		{
			$this->returnJson([
				'errorCode' => $authError,
				'error' => $message
			]);
		}
		else
		{
			Craft::$app->getSession()->setError($message);

			Craft::$app->urlManager->setRouteVariables([
				'loginName'    => Craft::$app->request->getPost('loginName'),
				'rememberMe'   => (bool) Craft::$app->request->getPost('rememberMe'),
				'errorCode'    => $authError,
				'errorMessage' => $message,
			]);
		}
	}

	/**
	 * Redirects the user after a successful login attempt, or if they visited the Login page while they were already
	 * logged in.
	 *
	 * @param bool $setNotice Whether a flash notice should be set, if this isn't an Ajax request.
	 *
	 * @return null
	 */
	private function _handleSuccessfulLogin($setNotice)
	{
		// Get the current user
		$currentUser = Craft::$app->getUser()->getIdentity();

		// If this is a CP request and they can access the control panel, set the default return URL to wherever
		// postCpLoginRedirect tells us
		if (Craft::$app->request->isCpRequest() && $currentUser->can('accessCp'))
		{
			$postCpLoginRedirect = Craft::$app->config->get('postCpLoginRedirect');
			$defaultReturnUrl = UrlHelper::getCpUrl($postCpLoginRedirect);
		}
		else
		{
			// Otherwise send them wherever postLoginRedirect tells us
			$postLoginRedirect = Craft::$app->config->get('postLoginRedirect');
			$defaultReturnUrl = UrlHelper::getSiteUrl($postLoginRedirect);
		}

		// Were they trying to access a URL beforehand?
		$returnUrl = Craft::$app->getUser()->getReturnUrl($defaultReturnUrl);

		// Clear it out
		Craft::$app->getUser()->removeReturnUrl();

		// If this was an Ajax request, just return success:true
		if (Craft::$app->request->isAjaxRequest())
		{
			$this->returnJson([
				'success' => true,
				'returnUrl' => $returnUrl
			]);
		}
		else
		{
			if ($setNotice)
			{
				Craft::$app->getSession()->setNotice(Craft::t('Logged in.'));
			}

			$this->redirectToPostedUrl($currentUser, $returnUrl);
		}
	}

	/**
	 * @param $user
	 *
	 * @return null
	 */
	private function _processSetPasswordPath($user)
	{
		// If the user cannot access the CP
		if (!$user->can('accessCp'))
		{
			// Make sure we're looking at the front-end templates path to start with.
			Craft::$app->path->setTemplatesPath(Craft::$app->path->getSiteTemplatesPath());

			// If they haven't defined a front-end set password template
			if (!Craft::$app->templates->doesTemplateExist(Craft::$app->config->getLocalized('setPasswordPath')))
			{
				// Set the Path service to use the CP templates path instead
				Craft::$app->path->setTemplatesPath(Craft::$app->path->getCpTemplatesPath());
			}
		}
		// The user can access the CP, so send them to Craft's set password template in the dashboard.
		else
		{
			Craft::$app->path->setTemplatesPath(Craft::$app->path->getCpTemplatesPath());
		}
	}

	/**
	 * Throws a "no user exists" exception
	 *
	 * @param int $userId
	 *
	 * @throws Exception
	 * @return null
	 */
	private function _noUserExists($userId)
	{
		throw new Exception(Craft::t('No user exists with the ID “{id}”.', ['id' => $userId]));
	}

	/**
	 * Verifies that the current user's password was submitted with the request.
	 *
	 * @return bool
	 */
	private function _verifyExistingPassword()
	{
		$currentUser = Craft::$app->getUser()->getIdentity();

		if (!$currentUser)
		{
			return false;
		}

		$currentHashedPassword = $currentUser->password;
		$currentPassword = Craft::$app->request->getRequiredParam('password');

		return Craft::$app->getSecurity()->validatePassword($currentPassword, $currentHashedPassword);
	}

	/**
	 * @param $user
	 *
	 * @return null
	 */
	private function _processUserPhoto($user)
	{
		// Delete their photo?
		if (Craft::$app->request->getPost('deleteUserPhoto'))
		{
			Craft::$app->users->deleteUserPhoto($user);
		}

		// Did they upload a new one?
		if ($userPhoto = UploadedFile::getInstanceByName('userPhoto'))
		{
			Craft::$app->users->deleteUserPhoto($user);
			$image = Craft::$app->images->loadImage($userPhoto->getTempName());
			$imageWidth = $image->getWidth();
			$imageHeight = $image->getHeight();

			$dimension = min($imageWidth, $imageHeight);
			$horizontalMargin = ($imageWidth - $dimension) / 2;
			$verticalMargin = ($imageHeight - $dimension) / 2;
			$image->crop($horizontalMargin, $imageWidth - $horizontalMargin, $verticalMargin, $imageHeight - $verticalMargin);

			Craft::$app->users->saveUserPhoto($userPhoto->getName(), $image, $user);

			IOHelper::deleteFile($userPhoto->getTempName());
		}
	}

	/**
	 * @param $user
	 *
	 * @return null
	 */
	private function _processUserGroupsPermissions($user)
	{
		// Save any user groups
		if (Craft::$app->getEdition() == Craft::Pro && Craft::$app->getUser()->checkPermission('assignUserPermissions'))
		{
			// Save any user groups
			$groupIds = Craft::$app->request->getPost('groups');

			if ($groupIds !== null)
			{
				Craft::$app->userGroups->assignUserToGroups($user->id, $groupIds);
			}

			// Save any user permissions
			if ($user->admin)
			{
				$permissions = [];
			}
			else
			{
				$permissions = Craft::$app->request->getPost('permissions');
			}

			if ($permissions !== null)
			{
				Craft::$app->userPermissions->saveUserPermissions($user->id, $permissions);
			}
		}
	}

	/**
	 * @return array
	 * @throws HttpException
	 */
	private function _processTokenRequest()
	{
		if (!Craft::$app->getUser()->getIsGuest())
		{
			Craft::$app->getUser()->logout();
		}

		$id            = Craft::$app->request->getRequiredParam('id');
		$userToProcess = Craft::$app->users->getUserByUid($id);
		$code          = Craft::$app->request->getRequiredParam('code');
		$isCodeValid   = false;

		if ($userToProcess)
		{
			// Fire a 'beforeVerifyUser' event
			Craft::$app->users->trigger(Users::EVENT_BEFORE_VERIFY_EMAIL, new UserEvent([
				'user' => $userToProcess
			]));

			$isCodeValid = Craft::$app->users->isVerificationCodeValidForUser($userToProcess, $code);
		}

		if (!$userToProcess || !$isCodeValid)
		{
			$this->_processInvalidToken($userToProcess);
		}

		// Fire an 'afterVerifyUser' event
		Craft::$app->users->trigger(Users::EVENT_AFTER_VERIFY_EMAIL, new UserEvent([
			'user' => $userToProcess
		]));

		return ['code' => $code, 'id' => $id, 'userToProcess' => $userToProcess];
	}

	/**
	 * @param UserModel $user
	 *
	 * @throws HttpException
	 */
	private function _processInvalidToken($user)
	{
		$url = Craft::$app->config->getLocalized('invalidUserTokenPath');

		// TODO: Remove this code in Craft 4
		if ($url == '')
		{
			// Check the deprecated config setting.
			$url = Craft::$app->config->getLocalized('activateAccountFailurePath');

			if ($url)
			{
				Craft::$app->deprecator->log('activateAccountFailurePath', 'The ‘activateAccountFailurePath’ has been deprecated. Use ‘invalidUserTokenPath’ instead.');
			}
		}

		if ($url != '')
		{
			$this->redirect(UrlHelper::getSiteUrl($url));
		}
		else
		{
			if ($user && $user->can('accessCp'))
			{
				$url = UrlHelper::getCpUrl(Craft::$app->config->getLoginPath());
			}
			else
			{
				$url = UrlHelper::getSiteUrl(Craft::$app->config->getLoginPath());
			}

			throw new HttpException('200', Craft::t('Invalid verification code. Please [login or reset your password]({loginUrl}).', ['loginUrl' => $url]));
		}
	}

	/**
	 * Takes over after a user has been activated.
	 *
	 * @param UserModel $user
	 */
	private function _onAfterActivateUser(UserModel $user)
	{
		// Should we log them in?
		$loggedIn = false;

		if (Craft::$app->config->get('autoLoginAfterAccountActivation'))
		{
			$loggedIn = Craft::$app->getUser()->loginByUserId($user->id, false, true);
		}

		// Can they access the CP?
		if ($user->can('accessCp'))
		{
			$postCpLoginRedirect = Craft::$app->config->get('postCpLoginRedirect');
			$url = UrlHelper::getCpUrl($postCpLoginRedirect);
		}
		else
		{
			$activateAccountSuccessPath = Craft::$app->config->getLocalized('activateAccountSuccessPath');
			$url = UrlHelper::getSiteUrl($activateAccountSuccessPath);
		}

		$this->redirect($url);
	}
}
