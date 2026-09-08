/**
 * Tuma Payments WooCommerce Blocks Integration
 */
const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { decodeEntities } = window.wp.htmlEntities;
const { createElement, useState, useEffect } = window.wp.element;

const settings = getSetting('tuma_payments_data', {});

const defaultLabel = 'M-Pesa via Tuma Payments';
const label = decodeEntities(settings.title) || defaultLabel;

/**
 * Content component with phone input
 */
const Content = (props) => {
    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup } = eventRegistration;
    const [phone, setPhone] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        const unsubscribe = onPaymentSetup(() => {
            // Validate phone number
            if (!phone || phone.trim() === '') {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: 'Please enter your M-Pesa phone number.',
                };
            }

            // Normalize and validate phone format
            let normalizedPhone = phone.replace(/[^0-9+]/g, '').replace(/^\+/, '');
            
            // Check valid Kenyan phone formats
            const isValid = /^(07[0-9]{8}|01[0-9]{8}|254[0-9]{9})$/.test(normalizedPhone);
            
            if (!isValid) {
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: 'Please enter a valid M-Pesa phone number (e.g., 0712345678 or 254712345678)',
                };
            }

            // Normalize to 254 format
            if (/^0[17][0-9]{8}$/.test(normalizedPhone)) {
                normalizedPhone = '254' + normalizedPhone.substring(1);
            }

            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        tuma_phone: normalizedPhone,
                    },
                },
            };
        });

        return () => unsubscribe();
    }, [onPaymentSetup, phone, emitResponse.responseTypes.ERROR, emitResponse.responseTypes.SUCCESS]);

    const handlePhoneChange = (e) => {
        setPhone(e.target.value);
        setError('');
    };

    return createElement(
        'div',
        { 
            className: 'tuma-payments-fields',
            style: { padding: '8px 0' }
        },
        createElement(
            'div',
            { style: { marginTop: '12px' } },
            createElement('label', { 
                htmlFor: 'tuma-phone',
                style: { 
                    display: 'block', 
                    marginBottom: '8px',
                    fontWeight: '600',
                    fontSize: '14px'
                }
            }, 'M-Pesa Phone Number ', createElement('span', { style: { color: '#cc0000' } }, '*')),
            createElement('input', {
                type: 'tel',
                id: 'tuma-phone',
                name: 'tuma_phone',
                value: phone,
                onChange: handlePhoneChange,
                placeholder: '0712345678',
                autoComplete: 'tel',
                style: {
                    width: '100%',
                    padding: '12px',
                    border: '1px solid #8c8f94',
                    borderRadius: '4px',
                    fontSize: '16px',
                    boxSizing: 'border-box',
                    marginBottom: '8px'
                }
            }),
            createElement('small', { 
                style: { 
                    display: 'block', 
                    color: '#646970',
                    fontSize: '12px',
                    lineHeight: '1.4'
                } 
            }, 'Format: 07xxxxxxxx or 254xxxxxxxxx'),
            error && createElement('p', { 
                style: { color: '#cc0000', marginTop: '8px', fontSize: '14px' } 
            }, error)
        )
    );
};

/**
 * Label component
 */
const Label = (props) => {
    const { PaymentMethodLabel } = props.components;
    const icon = settings.icon ? createElement('img', {
        src: settings.icon,
        alt: label,
        style: {
            height: '24px',
            marginRight: '8px',
            verticalAlign: 'middle'
        }
    }) : null;
    
    return createElement(
        'span',
        { style: { display: 'flex', alignItems: 'center' } },
        icon,
        createElement(PaymentMethodLabel, { text: label })
    );
};

/**
 * Tuma Payments method config
 */
const tumaPaymentsMethod = {
    name: 'tuma_payments',
    label: createElement(Label, null),
    content: createElement(Content, null),
    edit: createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports || ['products'],
    },
};

registerPaymentMethod(tumaPaymentsMethod);
