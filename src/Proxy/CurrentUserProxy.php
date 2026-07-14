<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fraym\Proxy;

use Fraym\Container;
use Fraym\Interface\CurrentUser;

/**
 * Прокси-объект для константы CURRENT_USER.
 * Делегирует все вызовы в Container::make('current_user').
 * Setter-методы мутируют реальный объект и возвращают $this (прокси),
 * чтобы цепочки вызовов оставались на прокси.
 */
final class CurrentUserProxy implements CurrentUser
{
    public function blockedProfileEdit(): bool
    {
        return Container::make('current_user')->blockedProfileEdit();
    }

    public function isLogged(): bool
    {
        return Container::make('current_user')->isLogged();
    }

    public function isBanned(): bool
    {
        return Container::make('current_user')->isBanned();
    }

    public function isAdmin(bool $checkAdminDataAllRights = false): bool
    {
        return Container::make('current_user')->isAdmin($checkAdminDataAllRights);
    }

    public function checkAllRights(string $right_id): bool
    {
        return Container::make('current_user')->checkAllRights($right_id);
    }

    public function authLogout(?string $byeMessage = null): void
    {
        Container::make('current_user')->authLogout($byeMessage);
    }

    public function auth(): void
    {
        Container::make('current_user')->auth();
    }

    public function authSetUserData(array $userData): void
    {
        Container::make('current_user')->authSetUserData($userData);
    }

    public function getId(): int|string|null
    {
        return Container::make('current_user')->getId();
    }

    public function setId(int|string|null $id): static
    {
        Container::make('current_user')->setId($id);

        return $this;
    }

    public function id(): int|string|null
    {
        return Container::make('current_user')->id();
    }

    public function getSid(): ?int
    {
        return Container::make('current_user')->getSid();
    }

    public function setSid(?int $sid): static
    {
        Container::make('current_user')->setSid($sid);

        return $this;
    }

    public function sid(): ?int
    {
        return Container::make('current_user')->sid();
    }

    public function getAllRights(): array
    {
        return Container::make('current_user')->getAllRights();
    }

    public function setAllRights(string|array|null $allRights): static
    {
        Container::make('current_user')->setAllRights($allRights);

        return $this;
    }

    public function getBazeCount(): int
    {
        return Container::make('current_user')->getBazeCount();
    }

    public function setBazeCount(int $bazeCount): static
    {
        Container::make('current_user')->setBazeCount($bazeCount);

        return $this;
    }

    public function getBlockSaveReferer(): bool
    {
        return Container::make('current_user')->getBlockSaveReferer();
    }

    public function setBlockSaveReferer(bool $blockSaveReferer): static
    {
        Container::make('current_user')->setBlockSaveReferer($blockSaveReferer);

        return $this;
    }

    public function getBlockAutoRedirect(): bool
    {
        return Container::make('current_user')->getBlockAutoRedirect();
    }

    public function setBlockAutoRedirect(bool $blockAutoRedirect): static
    {
        Container::make('current_user')->setBlockAutoRedirect($blockAutoRedirect);

        return $this;
    }

    public function getAdminData(): ?array
    {
        return Container::make('current_user')->getAdminData();
    }

    public function setAdminData(array $adminData): static
    {
        Container::make('current_user')->setAdminData($adminData);

        return $this;
    }

    public function isAuthenticatedViaBearer(): bool
    {
        return Container::make('current_user')->isAuthenticatedViaBearer();
    }

    public function setAuthenticatedViaBearer(bool $authenticatedViaBearer): static
    {
        Container::make('current_user')->setAuthenticatedViaBearer($authenticatedViaBearer);

        return $this;
    }
}
