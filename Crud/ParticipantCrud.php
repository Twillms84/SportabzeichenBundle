<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Crud;

use IServ\CrudBundle\Crud\ServiceCrud;
use IServ\CrudBundle\Mapper\FormMapper;
use IServ\CrudBundle\Mapper\ListMapper;
use IServ\CrudBundle\Mapper\ShowMapper;
use Psr\Container\ContainerInterface;
use PulsR\SportabzeichenBundle\Entity\SportabzeichenParticipant;

final class ParticipantCrud extends ServiceCrud
{
    
    protected static $entityClass = SportabzeichenParticipant::class;

    public function __construct(ContainerInterface $locator)
    {
        parent::__construct($locator, SportabzeichenParticipant::class);
    }

    protected function configure(): void
    {
        $this->title = _('Teilnehmer');
        $this->itemTitle = _('Teilnehmer');

        $this->canAdd = true;
        $this->canEdit = true;
        $this->canDelete = true;
    }

    public function configureListFields(ListMapper $list): void
    {
        $list
            ->addIdentifier('nachname', null, ['label' => _('Nachname')])
            ->add('vorname', null, ['label' => _('Vorname')])
            ->add('geschlecht', null, ['label' => _('Geschlecht')])
            ->add('geburtsdatum', 'date', ['label' => _('Geburtsdatum')]);
    }

    public function configureFormFields(FormMapper $form): void
    {
        $form
            ->add('vorname')
            ->add('nachname')
            ->add('geschlecht')
            ->add('geburtsdatum');
    }

    public function configureShowFields(ShowMapper $show): void
    {
        $show
            ->add('vorname')
            ->add('nachname')
            ->add('geschlecht')
            ->add('geburtsdatum', 'date');
    }
}
