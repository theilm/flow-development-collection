<?php
namespace Neos\Flow\Security\Authentication\Provider;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\Token\UsernamePasswordTokenInterface;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Cryptography\PrecomposedHashProvider;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;

/**
 * An authentication provider that authenticates
 * Neos\Flow\Security\Authentication\Token\UsernamePassword tokens.
 * The accounts are stored in the Content Repository.
 */
class PersistedUsernamePasswordProvider extends AbstractProvider
{
    /**
     * @var AccountRepository
     * @Flow\Inject
     */
    protected $accountRepository;

    /**
     * @var HashService
     * @Flow\Inject
     */
    protected $hashService;

    /**
     * @var Context
     * @Flow\Inject
     */
    protected $securityContext;

    /**
     * @var \Neos\Flow\Persistence\PersistenceManagerInterface
     * @Flow\Inject
     */
    protected $persistenceManager;

    /**
     * The PrecomposedHashProvider has to be injected non-lazy to prevent timing differences
     *
     * @var PrecomposedHashProvider
     * @Flow\Inject(lazy=false)
     */
    protected $precomposedHashProvider;

    /**
     * Returns the class names of the tokens this provider can authenticate.
     *
     * @return array
     */
    public function getTokenClassNames()
    {
        return [UsernamePasswordTokenInterface::class];
    }

    /**
     * Checks the given token for validity and sets the token authentication status
     * accordingly (success, wrong credentials or no credentials given).
     *
     * @param TokenInterface $authenticationToken The token to be authenticated
     * @return void
     * @throws UnsupportedAuthenticationTokenException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Security\Exception\InvalidAuthenticationStatusException
     */
    public function authenticate(TokenInterface $authenticationToken)
    {
        if (!($authenticationToken instanceof UsernamePasswordTokenInterface)) {
            throw new UnsupportedAuthenticationTokenException(sprintf('This provider cannot authenticate the given token. The token must implement %s', UsernamePasswordTokenInterface::class), 1217339840);
        }

        if ($authenticationToken->getAuthenticationStatus() !== TokenInterface::AUTHENTICATION_SUCCESSFUL) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);
        }

        $username = $authenticationToken->getUsername();
        $password = $authenticationToken->getPassword();

        if ($username === '' || $password === '') {
            return;
        }

        $providerName = $this->options['lookupProviderName'] ?? $this->name;
        $account = $this->securityContext->withoutAuthorizationChecks(function () use ($username, $providerName) {
            return $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName($username, $providerName);
        });

        $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);

        if ($account === null) {
            // validate anyways (with a precomposed hash) in order to prevent timing attacks on this provider
            $this->hashService->validatePassword($password, $this->precomposedHashProvider->getPrecomposedHash());
            return;
        }

        if ($this->hashService->validatePassword($password, $account->getCredentialsSource())) {
            $account->authenticationAttempted(TokenInterface::AUTHENTICATION_SUCCESSFUL);
            $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
            $authenticationToken->setAccount($account);
        } else {
            $account->authenticationAttempted(TokenInterface::WRONG_CREDENTIALS);
        }
        $this->accountRepository->update($account);
        $this->persistenceManager->allowObject($account);
    }
}
