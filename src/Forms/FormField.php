<?php

namespace SilverStripe\Forms;

use ReflectionClass;
use SilverStripe\Control\Controller;
use SilverStripe\Control\RequestHandler;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\SSViewer;

/**
 * Represents a field in a form.
 *
 * A FieldList contains a number of FormField objects which make up the whole of a form.
 *
 * In addition to single fields, FormField objects can be "composite", for example, the
 * {@link TabSet} field. Composite fields let us define complex forms without having to resort to
 * custom HTML.
 *
 * To subclass:
 *
 * Define a {@link dataValue()} method that returns a value suitable for inserting into a single
 * database field.
 *
 * For example, you might tidy up the format of a date or currency field. Define {@link saveInto()}
 * to totally customise saving.
 *
 * For example, data might be saved to the filesystem instead of the data record, or saved to a
 * component of the data record instead of the data record itself.
 *
 * A form field can be represented as structured data through {@link FormSchema},
 * including both structure (name, id, attributes, etc.) and state (field value).
 * Can be used by for JSON data which is consumed by a front-end application.
 */
class FormField extends RequestHandler
{
    use FormMessage;

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_STRING = 'String';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_HIDDEN = 'Hidden';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_TEXT = 'Text';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_HTML = 'HTML';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_INTEGER = 'Integer';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_DECIMAL = 'Decimal';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_MULTISELECT = 'MultiSelect';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_SINGLESELECT = 'SingleSelect';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_DATE = 'Date';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_DATETIME = 'DateTime';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_TIME = 'Time';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_BOOLEAN = 'Boolean';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_CUSTOM = 'Custom';

    /** @see $schemaDataType */
    const SCHEMA_DATA_TYPE_STRUCTURAL = 'Structural';

    /**
     * @var Form
     */
    protected $form;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var null|string
     */
    protected $title;

    /**
     * @var mixed
     */
    protected $value;

    /**
     * @var string
     */
    protected $extraClass;

    /**
     * Adds a title attribute to the markup.
     *
     * @var string
     *
     * @todo Implement in all subclasses
     */
    protected $description;

    /**
     * Extra CSS classes for the FormField container.
     *
     * @var array
     */
    protected $extraClasses;

    /**
     * @config
     * @var array $default_classes The default classes to apply to the FormField
     */
    private static $default_classes = [];

    /**
     * Right-aligned, contextual label for the field.
     *
     * @var string
     */
    protected $rightTitle;

    /**
     * Left-aligned, contextual label for the field.
     *
     * @var string
     */
    protected $leftTitle;

    /**
     * Stores a reference to the FieldList that contains this object.
     *
     * @var FieldList
     */
    protected $containerFieldList;

    /**
     * @var bool
     */
    protected $readonly = false;

    /**
     * @var bool
     */
    protected $disabled = false;

    /**
     * Custom validation message for the field.
     *
     * @var string
     */
    protected $customValidationMessage = '';

    /**
     * Name of the template used to render this form field. If not set, then will look up the class
     * ancestry for the first matching template where the template name equals the class name.
     *
     * To explicitly use a custom template or one named other than the form field see
     * {@link setTemplate()}.
     *
     * @var string
     */
    protected $template;

    /**
     * Name of the template used to render this form field. If not set, then will look up the class
     * ancestry for the first matching template where the template name equals the class name.
     *
     * To explicitly use a custom template or one named other than the form field see
     * {@link setFieldHolderTemplate()}.
     *
     * @var string
     */
    protected $fieldHolderTemplate;

    /**
     * @var string
     */
    protected $smallFieldHolderTemplate;

    /**
     * All attributes on the form field (not the field holder).
     *
     * Partially determined based on other instance properties.
     *
     * @see getAttributes()
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * The data type backing the field. Represents the type of value the
     * form expects to receive via a postback. Should be set in subclasses.
     *
     * The values allowed in this list include:
     *
     *   - String: Single line text
     *   - Hidden: Hidden field which is posted back without modification
     *   - Text: Multi line text
     *   - HTML: Rich html text
     *   - Integer: Whole number value
     *   - Decimal: Decimal value
     *   - MultiSelect: Select many from source
     *   - SingleSelect: Select one from source
     *   - Date: Date only
     *   - DateTime: Date and time
     *   - Time: Time only
     *   - Boolean: Yes or no
     *   - Custom: Custom type declared by the front-end component. For fields with this type,
     *     the component property is mandatory, and will determine the posted value for this field.
     *   - Structural: Represents a field that is NOT posted back. This may contain other fields,
     *     or simply be a block of stand-alone content. As with 'Custom',
     *     the component property is mandatory if this is assigned.
     *
     * Each value has an equivalent constant, e.g. {@link self::SCHEMA_DATA_TYPE_STRING}.
     *
     * @var string
     */
    protected $schemaDataType;

    /**
     * The type of front-end component to render the FormField as.
     *
     * @skipUpgrade
     * @var string
     */
    protected $schemaComponent;

    /**
     * Structured schema data representing the FormField.
     * Used to render the FormField as a ReactJS Component on the front-end.
     *
     * @var array
     */
    protected $schemaData = [];

    private static $casting = array(
        'FieldHolder' => 'HTMLFragment',
        'SmallFieldHolder' => 'HTMLFragment',
        'Field' => 'HTMLFragment',
        'AttributesHTML' => 'HTMLFragment', // property $AttributesHTML version
        'getAttributesHTML' => 'HTMLFragment', // method $getAttributesHTML($arg) version
        'Value' => 'Text',
        'extraClass' => 'Text',
        'ID' => 'Text',
        'isReadOnly' => 'Boolean',
        'HolderID' => 'Text',
        'Title' => 'Text',
        'RightTitle' => 'Text',
        'Description' => 'HTMLFragment',
    );

    /**
     * Structured schema state representing the FormField's current data and validation.
     * Used to render the FormField as a ReactJS Component on the front-end.
     *
     * @var array
     */
    protected $schemaState = [];

    /**
     * Takes a field name and converts camelcase to spaced words. Also resolves combined field
     * names with dot syntax to spaced words.
     *
     * Examples:
     *
     * - 'TotalAmount' will return 'Total Amount'
     * - 'Organisation.ZipCode' will return 'Organisation Zip Code'
     *
     * @param string $fieldName
     *
     * @return string
     */
    public static function name_to_label($fieldName)
    {
        if (strpos($fieldName, '.') !== false) {
            $parts = explode('.', $fieldName);

            $label = $parts[count($parts) - 2] . ' ' . $parts[count($parts) - 1];
        } else {
            $label = $fieldName;
        }

        return preg_replace('/([a-z]+)([A-Z])/', '$1 $2', $label);
    }

    /**
     * Construct and return HTML tag.
     *
     * @param string $tag
     * @param array $attributes
     * @param null|string $content
     *
     * @return string
     */
    public static function create_tag($tag, $attributes, $content = null)
    {
        $preparedAttributes = '';

        foreach ($attributes as $attributeKey => $attributeValue) {
            if (!empty($attributeValue) || $attributeValue === '0' || ($attributeKey == 'value' && $attributeValue !== null)) {
                $preparedAttributes .= sprintf(
                    ' %s="%s"',
                    $attributeKey,
                    Convert::raw2att($attributeValue)
                );
            }
        }

        if ($content || $tag != 'input') {
            return sprintf(
                '<%s%s>%s</%s>',
                $tag,
                $preparedAttributes,
                $content,
                $tag
            );
        }

        return sprintf(
            '<%s%s />',
            $tag,
            $preparedAttributes
        );
    }

    /**
     * Creates a new field.
     *
     * @param string $name The internal field name, passed to forms.
     * @param null|string $title The human-readable field label.
     * @param mixed $value The value of the field.
     */
    public function __construct($name, $title = null, $value = null)
    {
        $this->setName($name);

        if ($title === null) {
            $this->title = self::name_to_label($name);
        } else {
            $this->title = $title;
        }

        if ($value !== null) {
            $this->setValue($value);
        }

        parent::__construct();

        $this->setupDefaultClasses();
    }

    /**
     * Set up the default classes for the form. This is done on construct so that the default classes can be removed
     * after instantiation
     */
    protected function setupDefaultClasses()
    {
        $defaultClasses = self::config()->get('default_classes');
        if ($defaultClasses) {
            foreach ($defaultClasses as $class) {
                $this->addExtraClass($class);
            }
        }
    }

    /**
     * Return a link to this field.
     *
     * @param string $action
     *
     * @return string
     */
    public function Link($action = null)
    {
        return Controller::join_links($this->form->FormAction(), 'field/' . $this->name, $action);
    }

    /**
     * Returns the HTML ID of the field.
     *
     * The ID is generated as FormName_FieldName. All Field functions should ensure that this ID is
     * included in the field.
     *
     * @return string
     */
    public function ID()
    {
        return $this->getTemplateHelper()->generateFieldID($this);
    }

    /**
     * Returns the HTML ID for the form field holder element.
     *
     * @return string
     */
    public function HolderID()
    {
        return $this->getTemplateHelper()->generateFieldHolderID($this);
    }

    /**
     * Returns the current {@link FormTemplateHelper} on either the parent
     * Form or the global helper set through the {@link Injector} layout.
     *
     * To customize a single {@link FormField}, use {@link setTemplate} and
     * provide a custom template name.
     *
     * @return FormTemplateHelper
     */
    public function getTemplateHelper()
    {
        if ($this->form) {
            return $this->form->getTemplateHelper();
        }

        return FormTemplateHelper::singleton();
    }

    /**
     * Returns the field name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the field value.
     *
     * @see FormField::setSubmittedValue()
     * @return mixed
     */
    public function Value()
    {
        return $this->value;
    }

    /**
     * Method to save this form field into the given {@link DataObject}.
     *
     * By default, makes use of $this->dataValue()
     *
     * @param DataObject|DataObjectInterface $record DataObject to save data into
     */
    public function saveInto(DataObjectInterface $record)
    {
        if ($this->name) {
            $record->setCastedField($this->name, $this->dataValue());
        }
    }

    /**
     * Returns the field value suitable for insertion into the data object.
     * @see Formfield::setValue()
     * @return mixed
     */
    public function dataValue()
    {
        return $this->value;
    }

    /**
     * Returns the field label - used by templates.
     *
     * @return string
     */
    public function Title()
    {
        return $this->title;
    }

    /**
     * Set the title of this formfield.
     * Note: This expects escaped HTML.
     *
     * @param string $title Escaped HTML for title
     * @return $this
     */
    public function setTitle($title)
    {
        $this->title = $title;
        return $this;
    }

    /**
     * Gets the contextual label than can be used for additional field description.
     * Can be shown to the right or under the field in question.
     *
     * @return string Contextual label text.
     */
    public function RightTitle()
    {
        return $this->rightTitle;
    }

    /**
     * Sets the right title for this formfield
     * Note: This expects escaped HTML.
     *
     * @param string $rightTitle Escaped HTML for title
     * @return $this
     */
    public function setRightTitle($rightTitle)
    {
        $this->rightTitle = $rightTitle;
        return $this;
    }

    /**
     * @return string
     */
    public function LeftTitle()
    {
        return $this->leftTitle;
    }

    /**
     * @param string $leftTitle
     *
     * @return $this
     */
    public function setLeftTitle($leftTitle)
    {
        $this->leftTitle = $leftTitle;

        return $this;
    }

    /**
     * Compiles all CSS-classes. Optionally includes a "form-group--no-label" class if no title was set on the
     * FormField.
     *
     * Uses {@link Message()} and {@link MessageType()} to add validation error classes which can
     * be used to style the contained tags.
     *
     * @return string
     */
    public function extraClass()
    {
        $classes = array();

        $classes[] = $this->Type();

        if ($this->extraClasses) {
            $classes = array_merge(
                $classes,
                array_values($this->extraClasses)
            );
        }

        if (!$this->Title()) {
            $classes[] = 'form-group--no-label';
        }

        // Allow custom styling of any element in the container based on validation errors,
        // e.g. red borders on input tags.
        //
        // CSS class needs to be different from the one rendered through {@link FieldHolder()}.
        if ($this->getMessage()) {
            $classes[] .= 'holder-' . $this->getMessageType();
        }

        return implode(' ', $classes);
    }

    /**
     * Add one or more CSS-classes to the FormField container.
     *
     * Multiple class names should be space delimited.
     *
     * @param string $class
     *
     * @return $this
     */
    public function addExtraClass($class)
    {
        $classes = preg_split('/\s+/', $class);

        foreach ($classes as $class) {
            $this->extraClasses[$class] = $class;
        }

        return $this;
    }

    /**
     * Remove one or more CSS-classes from the FormField container.
     *
     * @param string $class
     *
     * @return $this
     */
    public function removeExtraClass($class)
    {
        $classes = preg_split('/\s+/', $class);

        foreach ($classes as $class) {
            unset($this->extraClasses[$class]);
        }

        return $this;
    }

    /**
     * Set an HTML attribute on the field element, mostly an <input> tag.
     *
     * Some attributes are best set through more specialized methods, to avoid interfering with
     * built-in behaviour:
     *
     * - 'class': {@link addExtraClass()}
     * - 'title': {@link setDescription()}
     * - 'value': {@link setValue}
     * - 'name': {@link setName}
     *
     * Caution: this doesn't work on most fields which are composed of more than one HTML form
     * field.
     *
     * @param string $name
     * @param string $value
     *
     * @return $this
     */
    public function setAttribute($name, $value)
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    /**
     * Get an HTML attribute defined by the field, or added through {@link setAttribute()}.
     *
     * Caution: this doesn't work on all fields, see {@link setAttribute()}.
     *
     * @param string $name
     * @return string
     */
    public function getAttribute($name)
    {
        $attributes = $this->getAttributes();

        if (isset($attributes[$name])) {
            return $attributes[$name];
        }

        return null;
    }

    /**
     * Allows customization through an 'updateAttributes' hook on the base class.
     * Existing attributes are passed in as the first argument and can be manipulated,
     * but any attributes added through a subclass implementation won't be included.
     *
     * @return array
     */
    public function getAttributes()
    {
        $attributes = array(
            'type' => 'text',
            'name' => $this->getName(),
            'value' => $this->Value(),
            'class' => $this->extraClass(),
            'id' => $this->ID(),
            'disabled' => $this->isDisabled(),
            'readonly' => $this->isReadonly()
        );

        if ($this->Required()) {
            $attributes['required'] = 'required';
            $attributes['aria-required'] = 'true';
        }

        $attributes = array_merge($attributes, $this->attributes);

        $this->extend('updateAttributes', $attributes);

        return $attributes;
    }

    /**
     * Custom attributes to process. Falls back to {@link getAttributes()}.
     *
     * If at least one argument is passed as a string, all arguments act as excludes, by name.
     *
     * @param array $attributes
     *
     * @return string
     */
    public function getAttributesHTML($attributes = null)
    {
        $exclude = null;

        if (is_string($attributes)) {
            $exclude = func_get_args();
        }

        if (!$attributes || is_string($attributes)) {
            $attributes = $this->getAttributes();
        }

        $attributes = (array) $attributes;

        $attributes = array_filter($attributes, function ($v) {
            return ($v || $v === 0 || $v === '0');
        });

        if ($exclude) {
            $attributes = array_diff_key(
                $attributes,
                array_flip($exclude)
            );
        }

        // Create markup
        $parts = array();

        foreach ($attributes as $name => $value) {
            if ($value === true) {
                $parts[] = sprintf('%s="%s"', $name, $name);
            } else {
                $parts[] = sprintf('%s="%s"', $name, Convert::raw2att($value));
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Returns a version of a title suitable for insertion into an HTML attribute.
     *
     * @return string
     */
    public function attrTitle()
    {
        return Convert::raw2att($this->title);
    }

    /**
     * Returns a version of a title suitable for insertion into an HTML attribute.
     *
     * @return string
     */
    public function attrValue()
    {
        return Convert::raw2att($this->value);
    }

    /**
     * Set the field value.
     *
     * If a FormField requires specific behaviour for loading content from either the database
     * or a submitted form value they should override setSubmittedValue() instead.
     *
     * @param mixed $value Either the parent object, or array of source data being loaded
     * @param array|DataObject $data {@see Form::loadDataFrom}
     * @return $this
     */
    public function setValue($value, $data = null)
    {
        $this->value = $value;
        return $this;
    }

    /**
     * Set value assigned from a submitted form postback.
     * Can be overridden to handle custom behaviour for user-localised
     * data formats.
     *
     * @param mixed $value
     * @param array|DataObject $data
     * @return $this
     */
    public function setSubmittedValue($value, $data = null)
    {
        return $this->setValue($value, $data);
    }

    /**
     * Set the field name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the container form.
     *
     * This is called automatically when fields are added to forms.
     *
     * @param Form $form
     *
     * @return $this
     */
    public function setForm($form)
    {
        $this->form = $form;

        return $this;
    }

    /**
     * Get the currently used form.
     *
     * @return Form
     */
    public function getForm()
    {
        return $this->form;
    }

    /**
     * Return true if security token protection is enabled on the parent {@link Form}.
     *
     * @return bool
     */
    public function securityTokenEnabled()
    {
        $form = $this->getForm();

        if (!$form) {
            return false;
        }

        return $form->getSecurityToken()->isEnabled();
    }

    public function castingHelper($field)
    {
        // Override casting for field message
        if (strcasecmp($field, 'Message') === 0 && ($helper = $this->getMessageCastingHelper())) {
            return $helper;
        }
        return parent::castingHelper($field);
    }

    /**
     * Set the custom error message to show instead of the default format.
     *
     * Different from setError() as that appends it to the standard error messaging.
     *
     * @param string $customValidationMessage
     *
     * @return $this
     */
    public function setCustomValidationMessage($customValidationMessage)
    {
        $this->customValidationMessage = $customValidationMessage;

        return $this;
    }

    /**
     * Get the custom error message for this form field. If a custom message has not been defined
     * then just return blank. The default error is defined on {@link Validator}.
     *
     * @return string
     */
    public function getCustomValidationMessage()
    {
        return $this->customValidationMessage;
    }

    /**
     * Set name of template (without path or extension).
     *
     * Caution: Not consistently implemented in all subclasses, please check the {@link Field()}
     * method on the subclass for support.
     *
     * @param string $template
     *
     * @return $this
     */
    public function setTemplate($template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * @return string
     */
    public function getFieldHolderTemplate()
    {
        return $this->fieldHolderTemplate;
    }

    /**
     * Set name of template (without path or extension) for the holder, which in turn is
     * responsible for rendering {@link Field()}.
     *
     * Caution: Not consistently implemented in all subclasses, please check the {@link Field()}
     * method on the subclass for support.
     *
     * @param string $fieldHolderTemplate
     *
     * @return $this
     */
    public function setFieldHolderTemplate($fieldHolderTemplate)
    {
        $this->fieldHolderTemplate = $fieldHolderTemplate;

        return $this;
    }

    /**
     * @return string
     */
    public function getSmallFieldHolderTemplate()
    {
        return $this->smallFieldHolderTemplate;
    }

    /**
     * Set name of template (without path or extension) for the small holder, which in turn is
     * responsible for rendering {@link Field()}.
     *
     * Caution: Not consistently implemented in all subclasses, please check the {@link Field()}
     * method on the subclass for support.
     *
     * @param string $smallFieldHolderTemplate
     *
     * @return $this
     */
    public function setSmallFieldHolderTemplate($smallFieldHolderTemplate)
    {
        $this->smallFieldHolderTemplate = $smallFieldHolderTemplate;

        return $this;
    }

    /**
     * Returns the form field.
     *
     * Although FieldHolder is generally what is inserted into templates, all of the field holder
     * templates make use of $Field. It's expected that FieldHolder will give you the "complete"
     * representation of the field on the form, whereas Field will give you the core editing widget,
     * such as an input tag.
     *
     * @param array $properties
     * @return DBHTMLText
     */
    public function Field($properties = array())
    {
        $context = $this;

        if (count($properties)) {
            $context = $context->customise($properties);
        }

        $this->extend('onBeforeRender', $this);

        $result = $context->renderWith($this->getTemplates());

        // Trim whitespace from the result, so that trailing newlines are supressed. Works for strings and HTMLText values
        if (is_string($result)) {
            $result = trim($result);
        } elseif ($result instanceof DBField) {
            $result->setValue(trim($result->getValue()));
        }

        return $result;
    }

    /**
     * Returns a "field holder" for this field.
     *
     * Forms are constructed by concatenating a number of these field holders.
     *
     * The default field holder is a label and a form field inside a div.
     *
     * @see FieldHolder.ss
     *
     * @param array $properties
     *
     * @return DBHTMLText
     */
    public function FieldHolder($properties = array())
    {
        $context = $this;

        if (count($properties)) {
            $context = $this->customise($properties);
        }

        return $context->renderWith($this->getFieldHolderTemplates());
    }

    /**
     * Returns a restricted field holder used within things like FieldGroups.
     *
     * @param array $properties
     *
     * @return string
     */
    public function SmallFieldHolder($properties = array())
    {
        $context = $this;

        if (count($properties)) {
            $context = $this->customise($properties);
        }

        return $context->renderWith($this->getSmallFieldHolderTemplates());
    }

    /**
     * Returns an array of templates to use for rendering {@link FieldHolder}.
     *
     * @return array
     */
    public function getTemplates()
    {
        return $this->_templates($this->getTemplate());
    }

    /**
     * Returns an array of templates to use for rendering {@link FieldHolder}.
     *
     * @return array
     */
    public function getFieldHolderTemplates()
    {
        return $this->_templates(
            $this->getFieldHolderTemplate(),
            '_holder'
        );
    }

    /**
     * Returns an array of templates to use for rendering {@link SmallFieldHolder}.
     *
     * @return array
     */
    public function getSmallFieldHolderTemplates()
    {
        return $this->_templates(
            $this->getSmallFieldHolderTemplate(),
            '_holder_small'
        );
    }


    /**
     * Generate an array of class name strings to use for rendering this form field into HTML.
     *
     * @param string $customTemplate
     * @param string $customTemplateSuffix
     *
     * @return array
     */
    protected function _templates($customTemplate = null, $customTemplateSuffix = null)
    {
        $templates = SSViewer::get_templates_by_class(get_class($this), $customTemplateSuffix, __CLASS__);
        // Prefer any custom template
        if ($customTemplate) {
            // Prioritise direct template
            array_unshift($templates, $customTemplate);
        }
        return $templates;
    }

    /**
     * Returns true if this field is a composite field.
     *
     * To create composite field types, you should subclass {@link CompositeField}.
     *
     * @return bool
     */
    public function isComposite()
    {
        return false;
    }

    /**
     * Returns true if this field has its own data.
     *
     * Some fields, such as titles and composite fields, don't actually have any data. It doesn't
     * make sense for data-focused methods to look at them. By overloading hasData() to return
     * false, you can prevent any data-focused methods from looking at it.
     *
     * @see FieldList::collateDataFields()
     *
     * @return bool
     */
    public function hasData()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isReadonly()
    {
        return $this->readonly;
    }

    /**
     * Sets a read-only flag on this FormField.
     *
     * Use performReadonlyTransformation() to transform this instance.
     *
     * Setting this to false has no effect on the field.
     *
     * @param bool $readonly
     *
     * @return $this
     */
    public function setReadonly($readonly)
    {
        $this->readonly = $readonly;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDisabled()
    {
        return $this->disabled;
    }

    /**
     * Sets a disabled flag on this FormField.
     *
     * Use performDisabledTransformation() to transform this instance.
     *
     * Setting this to false has no effect on the field.
     *
     * @param bool $disabled
     *
     * @return $this
     */
    public function setDisabled($disabled)
    {
        $this->disabled = $disabled;

        return $this;
    }

    /**
     * Returns a read-only version of this field.
     *
     * @return FormField
     */
    public function performReadonlyTransformation()
    {
        $readonlyClassName = static::class . '_Readonly';

        if (ClassInfo::exists($readonlyClassName)) {
            $clone = $this->castedCopy($readonlyClassName);
        } else {
            $clone = $this->castedCopy(ReadonlyField::class);
        }

        $clone->setReadonly(true);

        return $clone;
    }

    /**
     * Return a disabled version of this field.
     *
     * Tries to find a class of the class name of this field suffixed with "_Disabled", failing
     * that, finds a method {@link setDisabled()}.
     *
     * @return FormField
     */
    public function performDisabledTransformation()
    {
        $disabledClassName = $this->class . '_Disabled';

        if (ClassInfo::exists($disabledClassName)) {
            $clone = $this->castedCopy($disabledClassName);
        } else {
            $clone = clone $this;
        }

        $clone->setDisabled(true);

        return $clone;
    }

    /**
     * @param FormTransformation $transformation
     *
     * @return mixed
     */
    public function transform(FormTransformation $transformation)
    {
        return $transformation->transform($this);
    }

    /**
     * @param string $class
     *
     * @return int
     */
    public function hasClass($class)
    {
        $patten = '/' . strtolower($class) . '/i';

        $subject = strtolower($this->class . ' ' . $this->extraClass());

        return preg_match($patten, $subject);
    }

    /**
     * Returns the field type.
     *
     * The field type is the class name with the word Field dropped off the end, all lowercase.
     *
     * It's handy for assigning HTML classes. Doesn't signify the <input type> attribute.
     *
     * @see {link getAttributes()}.
     *
     * @return string
     */
    public function Type()
    {
        $type = new ReflectionClass($this);
        return strtolower(preg_replace('/Field$/', '', $type->getShortName()));
    }

    /**
     * @deprecated 4.0 Use FormField::create_tag()
     *
     * @param string $tag
     * @param array $attributes
     * @param null|string $content
     *
     * @return string
     */
    public function createTag($tag, $attributes, $content = null)
    {
        Deprecation::notice('4.0', 'Use FormField::create_tag()');

        return self::create_tag($tag, $attributes, $content);
    }

    /**
     * Abstract method each {@link FormField} subclass must implement, determines whether the field
     * is valid or not based on the value.
     *
     * @todo Make this abstract.
     *
     * @param Validator $validator
     * @return bool
     */
    public function validate($validator)
    {
        return true;
    }

    /**
     * Describe this field, provide help text for it.
     *
     * By default, renders as a <span class="description"> underneath the form field.
     *
     * @param string $description
     *
     * @return $this
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * @return string
     */
    public function debug()
    {
        return sprintf(
            '%s (%s: %s : <span style="color:red;">%s</span>) = %s',
            $this->class,
            $this->name,
            $this->title,
            $this->message,
            $this->value
        );
    }

    /**
     * This function is used by the template processor. If you refer to a field as a $ variable, it
     * will return the $Field value.
     *
     * @return string
     */
    public function forTemplate()
    {
        return $this->Field();
    }

    /**
     * @return bool
     */
    public function Required()
    {
        if ($this->form && ($validator = $this->form->getValidator())) {
            return $validator->fieldIsRequired($this->name);
        }

        return false;
    }

    /**
     * Set the FieldList that contains this field.
     *
     * @param FieldList $containerFieldList
     * @return $this
     */
    public function setContainerFieldList($containerFieldList)
    {
        $this->containerFieldList = $containerFieldList;
        return $this;
    }

    /**
     * Get the FieldList that contains this field.
     *
     * @return FieldList
     */
    public function getContainerFieldList()
    {
        return $this->containerFieldList;
    }

    /**
     * @return null|FieldList
     */
    public function rootFieldList()
    {
        if (is_object($this->containerFieldList)) {
            return $this->containerFieldList->rootFieldList();
        }

        user_error(
            "rootFieldList() called on $this->class object without a containerFieldList",
            E_USER_ERROR
        );

        return null;
    }

    /**
     * Returns another instance of this field, but "cast" to a different class. The logic tries to
     * retain all of the instance properties, and may be overloaded by subclasses to set additional
     * ones.
     *
     * Assumes the standard FormField parameter signature with its name as the only mandatory
     * argument. Mainly geared towards creating *_Readonly or *_Disabled subclasses of the same
     * type, or casting to a {@link ReadonlyField}.
     *
     * Does not copy custom field templates, since they probably won't apply to the new instance.
     *
     * @param mixed $classOrCopy Class name for copy, or existing copy instance to update
     *
     * @return FormField
     */
    public function castedCopy($classOrCopy)
    {
        $field = $classOrCopy;

        if (!is_object($field)) {
            $field = new $classOrCopy($this->name);
        }

        $field
            ->setValue($this->value)
            ->setForm($this->form)
            ->setTitle($this->Title())
            ->setLeftTitle($this->LeftTitle())
            ->setRightTitle($this->RightTitle())
            ->addExtraClass($this->extraClass) // Don't use extraClass(), since this merges calculated values
            ->setDescription($this->getDescription());

        // Only include built-in attributes, ignore anything set through getAttributes().
        // Those might change important characteristics of the field, e.g. its "type" attribute.
        foreach ($this->attributes as $attributeKey => $attributeValue) {
            $field->setAttribute($attributeKey, $attributeValue);
        }

        return $field;
    }

    /**
     * Determine if the value of this formfield accepts front-end submitted values and is saveable.
     *
     * @return bool
     */
    public function canSubmitValue()
    {
        return $this->hasData() && !$this->isReadonly() && !$this->isDisabled();
    }

    /**
     * Sets the component type the FormField will be rendered as on the front-end.
     *
     * @param string $componentType
     * @return FormField
     */
    public function setSchemaComponent($componentType)
    {
        $this->schemaComponent = $componentType;
        return $this;
    }

    /**
     * Gets the type of front-end component the FormField will be rendered as.
     *
     * @return string
     */
    public function getSchemaComponent()
    {
        return $this->schemaComponent;
    }

    /**
     * Sets the schema data used for rendering the field on the front-end.
     * Merges the passed array with the current `$schemaData` or {@link getSchemaDataDefaults()}.
     * Any passed keys that are not defined in {@link getSchemaDataDefaults()} are ignored.
     * If you want to pass around ad hoc data use the `data` array e.g. pass `['data' => ['myCustomKey' => 'yolo']]`.
     *
     * @param array $schemaData - The data to be merged with $this->schemaData.
     * @return FormField
     *
     * @todo Add deep merging of arrays like `data` and `attributes`.
     */
    public function setSchemaData($schemaData = [])
    {
        $defaults = $this->getSchemaData();
        $this->schemaData = array_merge($this->schemaData, array_intersect_key($schemaData, $defaults));
        return $this;
    }

    /**
     * Gets the schema data used to render the FormField on the front-end.
     *
     * @return array
     */
    public function getSchemaData()
    {
        $defaults = $this->getSchemaDataDefaults();
        return array_replace_recursive($defaults, array_intersect_key($this->schemaData, $defaults));
    }

    /**
     * @todo Throw exception if value is missing, once a form field schema is mandatory across the CMS
     *
     * @return string
     */
    public function getSchemaDataType()
    {
        return $this->schemaDataType;
    }

    /**
     * Gets the defaults for $schemaData.
     * The keys defined here are immutable, meaning undefined keys passed to {@link setSchemaData()} are ignored.
     * Instead the `data` array should be used to pass around ad hoc data.
     *
     * @return array
     */
    public function getSchemaDataDefaults()
    {
        return [
            'name' => $this->getName(),
            'id' => $this->ID(),
            'type' => $this->getSchemaDataType(),
            'component' => $this->getSchemaComponent(),
            'holderId' => $this->HolderID(),
            'title' => $this->Title(),
            'source' => null,
            'extraClass' => $this->extraClass(),
            'description' => $this->obj('Description')->getSchemaValue(),
            'rightTitle' => $this->RightTitle(),
            'leftTitle' => $this->LeftTitle(),
            'readOnly' => $this->isReadonly(),
            'disabled' => $this->isDisabled(),
            'customValidationMessage' => $this->getCustomValidationMessage(),
            'validation' => $this->getSchemaValidation(),
            'attributes' => [],
            'data' => [],
        ];
    }

    /**
     * Sets the schema data used for rendering the field on the front-end.
     * Merges the passed array with the current `$schemaState` or {@link getSchemaStateDefaults()}.
     * Any passed keys that are not defined in {@link getSchemaStateDefaults()} are ignored.
     * If you want to pass around ad hoc data use the `data` array e.g. pass `['data' => ['myCustomKey' => 'yolo']]`.
     *
     * @param array $schemaState The data to be merged with $this->schemaData.
     * @return FormField
     *
     * @todo Add deep merging of arrays like `data` and `attributes`.
     */
    public function setSchemaState($schemaState = [])
    {
        $defaults = $this->getSchemaState();
        $this->schemaState = array_merge($this->schemaState, array_intersect_key($schemaState, $defaults));
        return $this;
    }

    /**
     * Gets the schema state used to render the FormField on the front-end.
     *
     * @return array
     */
    public function getSchemaState()
    {
        $defaults = $this->getSchemaStateDefaults();
        return array_merge($defaults, array_intersect_key($this->schemaState, $defaults));
    }

    /**
     * Gets the defaults for $schemaState.
     * The keys defined here are immutable, meaning undefined keys passed to {@link setSchemaState()} are ignored.
     * Instead the `data` array should be used to pass around ad hoc data.
     * Includes validation data if the field is associated to a {@link Form},
     * and {@link Form->validate()} has been called.
     *
     * @todo Make form / field messages not always stored as html; Store value / casting as separate values.
     * @return array
     */
    public function getSchemaStateDefaults()
    {
        $state = [
            'name' => $this->getName(),
            'id' => $this->ID(),
            'value' => $this->Value(),
            'message' => $this->getSchemaMessage(),
            'data' => [],
        ];

        return $state;
    }

    /**
     * Return list of validation rules. Each rule is a key value pair.
     * The key is the rule name. The value is any information the frontend
     * validation handler can understand, or just `true` to enable.
     *
     * @return array
     */
    public function getSchemaValidation()
    {
        if ($this->Required()) {
            return [ 'required' => true ];
        }
        return [];
    }
}
