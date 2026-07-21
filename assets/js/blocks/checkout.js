(function () {
    const GATEWAY_NAME = 'piraeusbank_gateway';
    const settings = (window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting) ? window.wc.wcSettings.getSetting(GATEWAY_NAME + '_data', {}) : {};
    if (!settings) {
        return;
    }

    const label = window.wp && window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities(settings.title || '') : (settings.title || 'Piraeus Bank Gateway');
    const cardHolderEnabled = settings.cardHolderEnabled || false;
    const pb_installments = settings.pb_installments || 1;
    const pb_installments_variation = settings.pb_installments_variation || '';
    const no_installments_label = settings.no_installments_label || 'Without installments';

    const Content = () => {
        return window.wp.htmlEntities.decodeEntities(settings.description || '');
    };

    const Label = () => {
        const elements = [];

        elements.push(label);

        if (settings.icon) {
            elements.push(
                Object(window.wp.element.createElement)('img', {
                    src: settings.icon,
                    alt: label,
                    style: {
                        marginRight: '8px',
                        height: '24px',
                        verticalAlign: 'middle'
                    }
                })
            );
        }

        return Object(window.wp.element.createElement)('span', null, ...elements);
    };

    const Block_Gateway = {
        name: GATEWAY_NAME,
        // label: label,
        label: Object(window.wp.element.createElement)(Label, null),
        content: Object(window.wp.element.createElement)(Content, null),
        edit: Object(window.wp.element.createElement)(Content, null),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports,
        },
    };
    window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);

    /**
     * toggle visibility of fields based on selected payment method
     * and update installments options on cart update
     */
    const { select, subscribe } = wp.data;
    const cardFieldId = 'piraeusbank-card-holder';
    const installmentsFieldId = 'piraeusbank-installments';
    let previousPaymentMethod = '';

    function getFieldEl(fieldId) {
        return document.querySelector(`[data-field-id="${fieldId}"]`);
    }

    function updateInstallmentsOptions(amount) {
        let max_installments = pb_installments ?? 1;

        if ( pb_installments_variation ) {
            max_installments = 1;
            const installments_split = pb_installments_variation.split(',');
            installments_split.forEach(value => {
                const installment = value.split(':');
                if ( ( Array.isArray(installment) && installment.length !== 2 ) ||
                    ( isNaN(installment[0]) || isNaN(installment[1]) ) ) {
                    return;
                }

                if ( amount >= ( installment[0] ) ) {
                    max_installments = installment[1];
                }
            });
        }

        const options = [];
        for ( let i = 1; i <= max_installments; i++ ) {
            if( i === 1 ) {
                options.push( { value: i, label: no_installments_label } );
            } else {
                options.push( { value: i, label: i.toString() } );
            }
        }

        const instField = getFieldEl(installmentsFieldId);
        if (instField) {
            instField.innerHTML = '';
            options.forEach(option => {
                const optionEl = document.createElement('option');
                optionEl.value = option.value;
                optionEl.textContent = option.label;
                instField.appendChild(optionEl);
            });
        }
    }

    const onCartUpdate = () => {
        const paymentStore = select( 'wc/store/payment' );
        const currentPaymentMethod = paymentStore.getActivePaymentMethod();
        if ( currentPaymentMethod !== GATEWAY_NAME ) {
            return;
        }

        const totals = select( 'wc/store/cart' ).getCartTotals();

        updateInstallmentsOptions( totals.total_price / 100 ); // total_price is in cents
    };
    subscribe( onCartUpdate, 'wc/store/cart' );

    const onPaymentChange = () => {
        const paymentStore = select( 'wc/store/payment' );

        const currentPaymentMethod = paymentStore.getActivePaymentMethod();
        if ( currentPaymentMethod !== previousPaymentMethod ) {
            previousPaymentMethod = currentPaymentMethod;

            const cardField = getFieldEl(cardFieldId);
            const instField = getFieldEl(installmentsFieldId);
            cardField && (cardField.closest('.wc-block-components-text-input').style.display = (currentPaymentMethod === GATEWAY_NAME && cardHolderEnabled) ? '' : 'none');
            instField && (instField.closest('.wc-block-components-select-input').style.display = (currentPaymentMethod === GATEWAY_NAME) ? '' : 'none');
        }
    };
    subscribe( onPaymentChange, 'wc/store/payment' );
})();
