<?php
declare(ENCODING = 'utf-8');
namespace F3\FLOW3\Persistence;

/*                                                                        *
 * This script is part of the TYPO3 project - inspiring people to share!  *
 *                                                                        *
 * TYPO3 is free software; you can redistribute it and/or modify it under *
 * the terms of the GNU General Public License version 2 as published by  *
 * the Free Software Foundation.                                          *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        */

/**
 * @package FLOW3
 * @subpackage Tests
 * @version $Id$
 */

require_once('Fixture/F3_FLOW3_Tests_Persistence_Fixture_Entity2.php');
require_once('Fixture/F3_FLOW3_Tests_Persistence_Fixture_Entity3.php');
require_once('Fixture/F3_FLOW3_Tests_Persistence_Fixture_DirtyEntity.php');
require_once('Fixture/F3_FLOW3_Tests_Persistence_Fixture_CleanEntity.php');

/**
 * Testcase for the Persistence Manager
 *
 * @package FLOW3
 * @subpackage Tests
 * @version $Id$
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License, version 2
 */
class ManagerTest extends \F3\Testing\BaseTestCase {

	/**
	 * @test
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function initializeRecognizesEntityAndValueObjects() {
		$mockReflectionService = $this->getMock('F3\FLOW3\Reflection\Service');
		$mockReflectionService->expects($this->any())->method('getClassNamesByTag')->will($this->onConsecutiveCalls(array('EntityClass'), array('ValueClass')));
		$mockClassSchemataBuilder = $this->getMock('F3\FLOW3\Persistence\ClassSchemataBuilder', array(), array(), '', FALSE);
			// with() here holds the important assertion
		$mockClassSchemataBuilder->expects($this->once())->method('build')->with(array('EntityClass', 'ValueClass'))->will($this->returnValue(array()));
		$mockBackend = $this->getMock('F3\FLOW3\Persistence\BackendInterface');

		$manager = new \F3\FLOW3\Persistence\Manager($mockBackend);
		$manager->injectReflectionService($mockReflectionService);
		$manager->injectClassSchemataBuilder($mockClassSchemataBuilder);

		$manager->initialize();
	}

	/**
	 * @test
	 * @author Andreas Förthner <andreas.foerthner@netlogix.de>
	 */
	public function persistAllCanBeCalledIfNoRepositoryClassesAreFound() {
		$mockReflectionService = $this->getMock('F3\FLOW3\Reflection\Service');
		$mockReflectionService->expects($this->any())->method('getAllImplementationClassNamesForInterface')->will($this->returnValue(array()));
		$mockClassSchemataBuilder = $this->getMock('F3\FLOW3\Persistence\ClassSchemataBuilder', array(), array(), '', FALSE);
		$mockBackend = $this->getMock('F3\FLOW3\Persistence\BackendInterface');
		$session = new \F3\FLOW3\Persistence\Session();

		$manager = new \F3\FLOW3\Persistence\Manager($mockBackend);
		$manager->injectReflectionService($mockReflectionService);
		$manager->injectClassSchemataBuilder($mockClassSchemataBuilder);
		$manager->injectSession($session);

		$manager->persistAll();
	}

	/**
	 * @test
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function persistAllFindsObjectReferences() {
		$entity31 = new \F3\FLOW3\Tests\Persistence\Fixture\Entity3;
		$entity32 = new \F3\FLOW3\Tests\Persistence\Fixture\Entity3;
		$entity33 = new \F3\FLOW3\Tests\Persistence\Fixture\Entity3;
		$entity2 = new \F3\FLOW3\Tests\Persistence\Fixture\Entity2;
		$entity2->someString = 'Entity2';
		$entity2->someInteger = 42;
		$entity2->someReference = $entity31;
		$entity2->someReferenceArray = array($entity32, $entity33);

		$repository = new \F3\FLOW3\Persistence\Repository;
		$repository->add($entity2);

		$mockReflectionService = $this->getMock('F3\FLOW3\Reflection\Service');
		$mockReflectionService->expects($this->once())->method('getAllImplementationClassNamesForInterface')->with('F3\FLOW3\Persistence\RepositoryInterface')->will($this->returnValue(array('F3\FLOW3\Persistence\Repository')));
		$mockObjectManager = $this->getMock('F3\FLOW3\Object\ManagerInterface');
		$mockObjectManager->expects($this->once())->method('getObject')->with('F3\FLOW3\Persistence\Repository')->will($this->returnValue($repository));
		$session = new \F3\FLOW3\Persistence\Session();
		$mockBackend = $this->getMock('F3\FLOW3\Persistence\BackendInterface');
			// this is the really important assertion!
		$mockBackend->expects($this->once())->method('setAggregateRootObjects')->with(array(spl_object_hash($entity2) => $entity2));

		$manager = new \F3\FLOW3\Persistence\Manager($mockBackend);
		$manager->injectReflectionService($mockReflectionService);
		$manager->injectObjectManager($mockObjectManager);
		$manager->injectSession($session);

		$manager->persistAll();
	}

	/**
	 * @test
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function persistAllFindsReconstitutedObjects() {
		$dirtyEntity = new \F3\FLOW3\Tests\Persistence\Fixture\DirtyEntity();
		$session = new \F3\FLOW3\Persistence\Session();
		$session->registerReconstitutedObject($dirtyEntity);

		$mockReflectionService = $this->getMock('F3\FLOW3\Reflection\Service');
		$mockReflectionService->expects($this->any())->method('getAllImplementationClassNamesForInterface')->will($this->returnValue(array()));
		$mockReflectionService->expects($this->any())->method('getClassPropertyNames')->will($this->returnValue(array()));
		$mockClassSchemataBuilder = $this->getMock('F3\FLOW3\Persistence\ClassSchemataBuilder', array(), array(), '', FALSE);
		$mockBackend = $this->getMock('F3\FLOW3\Persistence\BackendInterface');
			// this is the really important assertion!
		$mockBackend->expects($this->once())->method('setAggregateRootObjects')->with(
			array(
				spl_object_hash($dirtyEntity) => $dirtyEntity
			)
		);

		$manager = new \F3\FLOW3\Persistence\Manager($mockBackend);
		$manager->injectReflectionService($mockReflectionService);
		$manager->injectClassSchemataBuilder($mockClassSchemataBuilder);
		$manager->injectSession($session);

		$manager->persistAll();
	}

	/**
	 * @test
	 * @author Karsten Dambekalns <karsten@typo3.org>
	 */
	public function persistAllFetchesRemovedObjects() {
		$entity1 = new \F3\FLOW3\Tests\Persistence\Fixture\CleanEntity;
		$entity3 = new \F3\FLOW3\Tests\Persistence\Fixture\CleanEntity;

		$repository = new \F3\FLOW3\Persistence\Repository;
		$repository->remove($entity1);

		$mockReflectionService = $this->getMock('F3\FLOW3\Reflection\Service');
		$mockReflectionService->expects($this->once())->method('getAllImplementationClassNamesForInterface')->with('F3\FLOW3\Persistence\RepositoryInterface')->will($this->returnValue(array('F3\FLOW3\Persistence\Repository')));
		$mockClassSchemataBuilder = $this->getMock('F3\FLOW3\Persistence\ClassSchemataBuilder', array(), array(), '', FALSE);
		$mockObjectManager = $this->getMock('F3\FLOW3\Object\ManagerInterface');
		$mockObjectManager->expects($this->once())->method('getObject')->with('F3\FLOW3\Persistence\Repository')->will($this->returnValue($repository));
		$session = new \F3\FLOW3\Persistence\Session();
		$session->registerReconstitutedObject($entity1);
		$session->registerReconstitutedObject($entity3);

		$mockBackend = $this->getMock('F3\FLOW3\Persistence\BackendInterface');
			// this is the really important assertion!
		$mockBackend->expects($this->once())->method('setDeletedObjects')->with(
			array(
				spl_object_hash($entity1) => $entity1
			)
		);

		$manager = new \F3\FLOW3\Persistence\Manager($mockBackend);
		$manager->injectReflectionService($mockReflectionService);
		$manager->injectClassSchemataBuilder($mockClassSchemataBuilder);
		$manager->injectSession($session);
		$manager->injectObjectManager($mockObjectManager);

		$manager->persistAll();

		$this->assertSame(array(spl_object_hash($entity3) => $entity3), $session->getReconstitutedObjects());
	}

}

?>
