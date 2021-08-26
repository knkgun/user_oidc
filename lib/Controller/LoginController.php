<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\UserOIDC\Controller;

use OCA\UserOIDC\Service\ProviderService;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWK;
use OCA\UserOIDC\Vendor\Firebase\JWT\JWT;
use OCA\UserOIDC\AppInfo\Application;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

class LoginController extends Controller {
	private const STATE = 'oidc.state';
	private const NONCE = 'oidc.nonce';
	private const PROVIDERID = 'oidc.providerid';

	/** @var ISecureRandom */
	private $random;

	/** @var ISession */
	private $session;

	/** @var IClientService */
	private $clientService;

	/** @var IURLGenerator */
	private $urlGenerator;

	/** @var UserMapper */
	private $userMapper;

	/** @var IUserSession */
	private $userSession;

	/** @var IUserManager */
	private $userManager;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var ProviderMapper */
	private $providerMapper;
	/**
	 * @var ILogger
	 */
	private $logger;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var ProviderService
	 */
	private $providerService;

	public function __construct(
		IRequest $request,
		ProviderMapper $providerMapper,
		ProviderService $providerService,
		ISecureRandom $random,
		ISession $session,
		IClientService $clientService,
		IURLGenerator $urlGenerator,
		UserMapper $userMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		ITimeFactory $timeFactory,
		IConfig $config,
		ILogger $logger
	) {
		parent::__construct(Application::APP_ID, $request);

		$this->random = $random;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->urlGenerator = $urlGenerator;
		$this->userMapper = $userMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->timeFactory = $timeFactory;
		$this->config = $config;
		$this->providerMapper = $providerMapper;
		$this->providerService = $providerService;
		$this->logger = $logger;
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function login(int $providerId, string $redirect_url = '') {
		$this->logger->debug('Initiating login for provider with id: ' . $providerId);

		//TODO: handle exceptions
		$provider = $this->providerMapper->getProvider($providerId);

		$state = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::STATE, $state);

		$nonce = $this->random->generate(32, ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_UPPER);
		$this->session->set(self::NONCE, $nonce);

		$this->session->set(self::PROVIDERID, $providerId);
		$this->session->close();

		$redirectArguments = [];
		if ($redirect_url) {
			$redirectArguments['redirect_url'] = $redirect_url;
		}
		$data = [
			'client_id' => $provider->getClientId(),
			'response_type' => 'code',
			'scope' => 'openid email profile',
			'claims' => json_encode([
				'id_token' => [
					'preferred_username' => ['essential' => true],
					'name' => ['essential' => true],
					'email' => ['essential' => true],
					'quota' => ['essential' => true],
				],
				//'userinfo' => [
				//	'preferred_username' => ['essential' => true],
				//],
			]),
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code', $redirectArguments),
			'state' => $state,
			'nonce' => $nonce,
		];

		$discovery = $this->obtainDiscovery($provider->getDiscoveryEndpoint());

		//TODO verify discovery

		$url = $discovery['authorization_endpoint'] . '?' . http_build_query($data);
		$this->logger->debug('Redirecting user to: ' . $url);
		error_log('Redirecting user to: ' . $url);

		return new RedirectResponse($url);
	}

	/**
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function code($state = '', $code = '', $scope = '', string $redirect_url = '') {
		$this->logger->debug('Code login with core: ' . $code . ' and state: ' . $state);

		if ($this->session->get(self::STATE) !== $state) {
			$this->logger->debug('state does not match');

			// TODO show page with forbidden
			return new JSONResponse([
				'got' => $state,
				'expected' => $this->session->get(self::STATE),
			], Http::STATUS_FORBIDDEN);
		}

		$providerId = (int)$this->session->get(self::PROVIDERID);
		$provider = $this->providerMapper->getProvider($providerId);

		$discovery = $this->obtainDiscovery($provider->getDiscoveryEndpoint());

		$this->logger->debug('Obtainting data from: ' . $discovery['token_endpoint']);

		$client = $this->clientService->newClient();
		$result = $client->post(
			$discovery['token_endpoint'],
			[
				'body' => [
					'code' => $code,
					'client_id' => $provider->getClientId(),
					'client_secret' => $provider->getClientSecret(),
					'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.login.code'),
					'grant_type' => 'authorization_code',
				],
			]
		);

		$data = json_decode($result->getBody(), true);
		foreach ($data as $k => $v) {
			error_log('----- token_endpoint data['.$k.']: ' . $v);
		}

		// Obtain jwks
		$client = $this->clientService->newClient();
		$result = json_decode($client->get($discovery['jwks_uri'])->getBody(), true);
		$this->logger->debug('Obtained the jwks');

		$jwks = JWK::parseKeySet($result);
		$this->logger->debug('Parsed the jwks');

		// TODO: proper error handling
		JWT::$leeway = 60;
		error_log('TOKEN : '.$data['id_token']);
		$payload = JWT::decode($data['id_token'], $jwks, array_keys(JWT::$supported_algs));

		$this->logger->debug('Parsed the JWT payload: ' . json_encode($payload, JSON_THROW_ON_ERROR));
		$prettyToken = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		error_log('DECODED login TOKEN : '.$prettyToken);

		if ($payload->exp < $this->timeFactory->getTime()) {
			$this->logger->debug('Token has expired');
			// TODO: error properly
			return new JSONResponse(['token expired']);
		}

		// Verify audience
		if (!(($payload->aud === $provider->getClientId() || in_array($provider->getClientId(), $payload->aud, true)))) {
			$this->logger->debug('This token is not for us');
			// TODO: error properly
			return new JSONResponse(['audience does not match']);
		}

		if (isset($payload->nonce) && $payload->nonce !== $this->session->get(self::NONCE)) {
			$this->logger->debug('Nonce does not match');
			// TODO: error properly
			return new JSONResponse(['invalid nonce']);
		}

		// get attribute mapping settings
		$uidAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_UID, 'sub');
		$emailAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_EMAIL, 'email');
		$displaynameAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_DISPLAYNAME, 'name');
		$quotaAttribute = $this->providerService->getSetting($providerId, ProviderService::SETTING_MAPPING_QUOTA, 'quota');

		// try to get id/name/email information from the token itself
		$userId = $payload->{$uidAttribute} ?? null;
		$userName = $payload->{$displaynameAttribute} ?? null;
		$email = $payload->{$emailAttribute} ?? null;
		$quota = $payload->{$quotaAttribute} ?? null;

		// if something is missing from the token, get user info from /userinfo endpoint
		if (is_null($userId) || is_null($userName) || is_null($email) || is_null($quota)) {
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $data['access_token'],
				],
			];
			$userInfoResult = json_decode($client->get($discovery['userinfo_endpoint'], $options)->getBody(), true);
			$userId = $userId ?? $userInfoResult[$uidAttribute] ?? null;
			$userName = $userName ?? $userInfoResult[$displaynameAttribute] ?? null;
			$email = $email ?? $userInfoResult[$emailAttribute] ?? null;
			$quota = $quota ?? $userInfoResult[$quotaAttribute] ?? null;
		}

		// Insert or update user
		if (is_null($userId)) {
			return new JSONResponse($payload);
		}
		$backendUser = $this->userMapper->getOrCreate($providerId, $userId);

		$this->logger->debug('User obtained: ' . $backendUser->getUserId());

		// update sub
		// store link between sub and user ID (to allow API requests with token only having 'sub')
		$backendUser->setSub($payload->{'sub'} ?? '');
		$backendUser = $this->userMapper->update($backendUser);

		// Update displayname
		if ($userName) {
			$newDisplayName = mb_substr($userName, 0, 255);
			if ($newDisplayName !== $backendUser->getDisplayName()) {
				$backendUser->setDisplayName($newDisplayName);
				$backendUser = $this->userMapper->update($backendUser);
				//TODO: dispatch event for the update
			}
		}

		$user = $this->userManager->get($backendUser->getUserId());
		if ($user === null) {
			return new JSONResponse(['Failed to provision user']);
		}

		// Update e-mail
		if ($email) {
			$this->logger->debug('Updating e-mail');
			$user->setEMailAddress($email);
		}

		if ($quota) {
			$user->setQuota($quota);
		}

		$this->logger->debug('Logging user in');

		$this->userSession->setUser($user);
		$this->userSession->completeLogin($user, ['loginName' => $user->getUID(), 'password' => '']);
		$this->userSession->createSessionToken($this->request, $user->getUID(), $user->getUID());

		$this->logger->debug('Redirecting user');

		// TODO: user proper redirect url

		error_log('CODE : redirect_url = ' . $redirect_url);
		return new RedirectResponse(\OC_Util::getDefaultPageUrl());
	}

	private function obtainDiscovery(string $url) {
		$client = $this->clientService->newClient();

		$this->logger->debug('Obtaining discovery endpoint: ' . $url);
		$response = $client->get($url);

		//TODO handle failures gracefull
		$body = json_decode($response->getBody(), true);
		return $body;
	}
}
