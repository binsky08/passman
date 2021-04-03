<?php
/**
 * Nextcloud - passman
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Sander Brand <brantje@gmail.com>
 * @copyright Sander Brand 2016
 */

namespace OCA\Passman\Controller;

use OCA\Passman\Db\SharingACL;
use OCA\Passman\Service\CredentialService;
use OCA\Passman\Service\SettingsService;
use OCA\Passman\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\ApiController;
use OCA\Passman\Service\FileService;

class FileController extends ApiController {
	private $userId;
	private $fileService;
	private $credentialService;
	private $sharingService;
	private $settings;

	public function __construct($AppName,
								IRequest $request,
								$UserId,
								FileService $fileService,
								CredentialService $credentialService,
								ShareService $sharingService,
								SettingsService $settings){
		parent::__construct(
			$AppName,
			$request,
			'GET, POST, DELETE, PUT, PATCH, OPTIONS',
			'Authorization, Content-Type, Accept',
			86400);
		$this->userId = $UserId;
		$this->fileService = $fileService;
		$this->credentialService = $credentialService;
		$this->sharingService = $sharingService;
		$this->settings = $settings;
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function uploadFile($data, $filename, $mimetype, $size, $shared_credential_guid) {
		$shared_key = null;
		$user_id = $this->userId;

		if ($shared_credential_guid !== null) {
			$storedCredential = $this->credentialService->getCredentialByGUID($shared_credential_guid);
			if (!hash_equals($storedCredential->getUserId(), $this->userId)) {
				$acl = $this->sharingService->getCredentialAclForUser($this->userId, $storedCredential->getGuid());
				if ($acl->hasPermission(SharingACL::WRITE) && $acl->hasPermission(SharingACL::FILES)) {
					$shared_key = $storedCredential->getSharedKey();
					$user_id = $storedCredential->getUserId();
				} else {
					return new DataResponse(['msg' => 'Not authorized'], Http::STATUS_UNAUTHORIZED);
				}
				if (!$this->settings->isEnabled('user_sharing_enabled')) {
					return new DataResponse(['msg' => 'Not authorized'], Http::STATUS_UNAUTHORIZED);
				}
			}
		}

		$file = array(
			'filename' => $filename,
			'size' => $size,
			'mimetype' => $mimetype,
			'file_data' => $data,
			'user_id' => $user_id
		);
		return new JSONResponse($this->fileService->createFile($file, $user_id, $shared_key));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getFile($file_id) {
		return new JSONResponse($this->fileService->getFile($file_id, $this->userId));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function deleteFile($file_id) {
		return new JSONResponse($this->fileService->deleteFile($file_id, $this->userId));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function updateFile($file_id, $file_data, $filename){
		try{
			$file = $this->fileService->getFile($file_id, $this->userId);
		} catch (\Exception $doesNotExistException){

		}
		if($file){
			if($file_data) {
				$file->setFileData($file_data);
			}
			if($filename) {
				$file->setFilename($filename);
			}
			if($filename || $file_data){
				new JSONResponse($this->fileService->updateFile($file));
			}
		}
	}
}
