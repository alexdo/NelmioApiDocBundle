<?php

/*
 * This file is part of the NelmioApiDocBundle package.
 *
 * (c) Nelmio
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\ApiDocBundle\ModelDescriber;

use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareInterface;
use Nelmio\ApiDocBundle\Describer\ModelRegistryAwareTrait;
use Nelmio\ApiDocBundle\Model\Model;
use Nelmio\ApiDocBundle\SwaggerPhp\Util;
use Swagger\Annotations\Definition;
use Swagger\Annotations\Items;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormConfigBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\ResolvedFormTypeInterface;
use Symfony\Component\PropertyInfo\Type;

/**
 * @internal
 */
final class FormModelDescriber implements ModelDescriberInterface, ModelRegistryAwareInterface
{
    use ModelRegistryAwareTrait;

    private $formFactory;

    public function __construct(FormFactoryInterface $formFactory = null)
    {
        $this->formFactory = $formFactory;
    }

    public function describe(Model $model, Definition $definition)
    {
        if (method_exists(AbstractType::class, 'setDefaultOptions')) {
            throw new \LogicException('symfony/form < 3.0 is not supported, please upgrade to an higher version to use a form as a model.');
        }
        if (null === $this->formFactory) {
            throw new \LogicException('You need to enable forms in your application to use a form as a model.');
        }

        $definition->type = 'object';

        $class = $model->getType()->getClassName();

        $form = $this->formFactory->create($class, null, $model->getOptions() ?? []);
        $this->parseForm($definition, $form);
    }

    public function supports(Model $model): bool
    {
        return is_a($model->getType()->getClassName(), FormTypeInterface::class, true);
    }

    private function parseForm(Definition $definition, FormInterface $form)
    {
        foreach ($form as $name => $child) {
            $config = $child->getConfig();
            $property = Util::getProperty($definition, $name);

            if ($config->getRequired()) {
                $required = $definition->required ?? [];
                $required[] = $name;

                $definition->required = $required;
            }

            if ($config->hasOption('documentation')) {
                $property->mergeProperties($config->getOption('documentation'));
            }

            if (null !== $property->type) {
                continue; // Type manually defined
            }

            $this->findFormType($config, $property);
        }
    }

    /**
     * Finds and sets the schema type on $property based on $config info.
     *
     * Returns true if a native Swagger type was found, false otherwise
     *
     * @param FormConfigBuilderInterface $config
     * @param                            $property
     */
    private function findFormType(FormConfigBuilderInterface $config, $property)
    {
        $type = $config->getType();

        if (!$builtinFormType = $this->getBuiltinFormType($type)) {
            // if form type is not builtin in Form component.
            $model = new Model(
                new Type(Type::BUILTIN_TYPE_OBJECT, false, get_class($type->getInnerType())),
                null,
                $config->getOptions()
            );
            $property->ref = $this->modelRegistry->register($model);

            return;
        }

        do {
            $blockPrefix = $builtinFormType->getBlockPrefix();

            if ('text' === $blockPrefix) {
                $property->type = 'string';

                break;
            }

            if ('number' === $blockPrefix) {
                $property->type = 'number';

                break;
            }

            if ('integer' === $blockPrefix) {
                $property->type = 'integer';

                break;
            }

            if ('date' === $blockPrefix) {
                $property->type = 'string';
                $property->format = 'date';

                break;
            }

            if ('datetime' === $blockPrefix) {
                $property->type = 'string';
                $property->format = 'date-time';

                break;
            }

            if ('choice' === $blockPrefix) {
                if ($config->getOption('multiple')) {
                    $property->type = 'array';
                } else {
                    $property->type = 'string';
                }
                if (($choices = $config->getOption('choices')) && is_array($choices) && count($choices)) {
                    $enums = array_values($choices);
                    if ($this->isNumbersArray($enums)) {
                        $type = 'number';
                    } elseif ($this->isBooleansArray($enums)) {
                        $type = 'boolean';
                    } else {
                        $type = 'string';
                    }

                    if ($config->getOption('multiple')) {
                        $property->items = Util::createChild($property, Items::class, ['type' => $type, 'enum' => $enums]);
                    } else {
                        $property->type = $type;
                        $property->enum = $enums;
                    }
                }

                break;
            }

            if ('checkbox' === $blockPrefix) {
                $property->type = 'boolean';

                break;
            }

            if ('password' === $blockPrefix) {
                $property->type = 'string';
                $property->format = 'password';

                break;
            }

            if ('repeated' === $blockPrefix) {
                $property->type = 'object';
                $property->required = [$config->getOption('first_name'), $config->getOption('second_name')];
                $subType = $config->getOption('type');

                foreach (['first', 'second'] as $subField) {
                    $subName = $config->getOption($subField.'_name');
                    $subForm = $this->formFactory->create($subType, null, array_merge($config->getOption('options'), $config->getOption($subField.'_options')));

                    $this->findFormType($subForm->getConfig(), Util::getProperty($property, $subName));
                }

                break;
            }

            if ('collection' === $blockPrefix) {
                $subType = $config->getOption('entry_type');
                $subOptions = $config->getOption('entry_options');
                $subForm = $this->formFactory->create($subType, null, $subOptions);

                $property->type = 'array';
                $property->items = Util::createChild($property, Items::class);

                $this->findFormType($subForm->getConfig(), $property->items);

                break;
            }

            // The DocumentType is bundled with the DoctrineMongoDBBundle
            if ('entity' === $blockPrefix || 'document' === $blockPrefix) {
                $entityClass = $config->getOption('class');

                if ($config->getOption('multiple')) {
                    $property->format = sprintf('[%s id]', $entityClass);
                    $property->type = 'array';
                    $property->items = Util::createChild($property, Items::class, ['type' => 'string']);
                } else {
                    $property->type = 'string';
                    $property->format = sprintf('%s id', $entityClass);
                }

                break;
            }
        } while ($builtinFormType = $builtinFormType->getParent());
    }

    /**
     * @param array $array
     *
     * @return bool true if $array contains only numbers, false otherwise
     */
    private function isNumbersArray(array $array): bool
    {
        foreach ($array as $item) {
            if (!is_numeric($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $array
     *
     * @return bool true if $array contains only booleans, false otherwise
     */
    private function isBooleansArray(array $array): bool
    {
        foreach ($array as $item) {
            if (!is_bool($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param ResolvedFormTypeInterface $type
     *
     * @return ResolvedFormTypeInterface|null
     */
    private function getBuiltinFormType(ResolvedFormTypeInterface $type)
    {
        do {
            $class = get_class($type->getInnerType());

            if (FormType::class === $class) {
                return null;
            }

            if (in_array($type->getBlockPrefix(), ['entity', 'document'], true)) {
                return $type;
            }

            if (0 === strpos($class, 'Symfony\Component\Form\Extension\Core\Type\\')) {
                return $type;
            }
        } while ($type = $type->getParent());

        return null;
    }
}
