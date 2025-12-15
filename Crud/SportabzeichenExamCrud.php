<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Crud;

use IServ\CrudBundle\Crud\ServiceCrud;
use IServ\CrudBundle\Mapper\FormMapper;
use IServ\CrudBundle\Mapper\ListMapper;
use IServ\CrudBundle\Mapper\ShowMapper;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenExam;
use Psr\Container\ContainerInterface;

final class SportabzeichenExamCrud extends ServiceCrud
{
    
    Protected static $entityClass = SportabzeichenExam::class;

    public function __construct(ContainerInterface $locator)
    {
        parent::__construct($locator, SportabzeichenExam::class);
    }

    protected function configure(): void
    {
        $this->title = _('Prüfungen');
        $this->itemTitle = _('Prüfung');

        $this->canAdd    = true;
        $this->canEdit   = true;
        $this->canDelete = true;
    }

    public function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('examName', null, ['label' => _('Bezeichnung')])
            ->add('examDate', 'date', ['label' => _('Datum')])
            ->add('examYear', null, ['label' => _('Jahr')])
            ->add('createdAt', 'datetime', ['label' => _('Erstellt am')]);
    }

    public function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('examName')
            ->add('examYear')
            ->add('examDate');
    }

    public function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('examName')
            ->add('examYear')
            ->add('examDate', 'date')
            ->add('createdAt', 'datetime')
            ->add('updatedAt', 'datetime');
    }
}
