<?php

namespace Yosimitso\WorkingForumBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Class SearchType
 *
 * @package Yosimitso\WorkingForumBundle\Form
 */
class SearchType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add(
                'keywords',
                TextType::class,
                [
                    'translation_domain' => 'YosimitsoWorkingForumBundle',
                    'label'              => 'search.keywords',
                    'required' => true,
                    'constraints' => [new Length(['min' => 3])],
                ]
            )
            ->add(
                'forum',
                EntityType::class,
                [
                    'class'              => 'YosimitsoWorkingForumBundle:Subforum',
                    'choice_label'       => 'name',
                    'multiple'           => true,
                    'label'              => 'search.search_in',
                    'translation_domain' => 'YosimitsoWorkingForumBundle',
                    'group_by'           => function ($sub) {
                        return $sub->getForum()->getName();
                    },

                ]
            )
            ->add(
                'submit',
                SubmitType::class,
                [
                    'label'              => 'forum.search_forum',
                    'translation_domain' => 'YosimitsoWorkingForumBundle',
                    'attr'               => ['class' => 'wf_button'],
                ]
            )
        ;
    }

    /**
     * @param OptionsResolver $resolver
     **/
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([]);
    }
}