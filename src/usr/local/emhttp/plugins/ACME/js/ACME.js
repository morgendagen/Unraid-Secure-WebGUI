"use strict";
import { AnsiUp } from './ansi_up.js'
import { parseFile } from './dnsapi.js'

export class ACME {
    constructor() {
        var self = this;
        this._dnsProviders = [];
        this._currentEnvironment = {};
        this._currentDnsProvider = null;
        this._nchanSubscriber = new NchanSubscriber('/sub/acmesh',{subscriber:'websocket', reconnectTimeout:5000});
        this._nchanSubscriber.on('message', function(data) {
            var box = $('pre#swaltext');
            const text = box.html().split('<br>');
            var html = self._ansi_up.ansi_to_html(data);
            text.push(html);
            box.html(text.join('<br>')).scrollTop(box[0].scrollHeight);
        });
        this._ansi_up = new AnsiUp();
        this.loadDnsProviders();
    }

    /**
     * Load the list of supported DNS providers.
     */
    loadDnsProviders() {
        var self = this;
        $.ajax({
            url:'/plugins/ACME/dnsapi_info.txt',
            success:function(infoTxt) {
                self._dnsProviders = parseFile(infoTxt)
                var dnsapiSelect = document.getElementById("ACME_DNSAPI");
                self._dnsProviders.forEach((currentValue, index, arr) => {
                    dnsapiSelect.options[index] = new Option(currentValue.Name, currentValue.Id, currentValue.Id == self._currentDnsProvider, currentValue.Id == self._currentDnsProvider);
                });
                self.dnsProviderChanged(dnsapiSelect);
            }
        });
    }

    /**
     * Set the currently active DNS provider environment options.
     * 
     * @param {Map<string,string>} env 
     */
    setCurrentEnvironment(env) {
        this._currentEnvironment = env;
    }

    /**
     * Set the id of the currently active DNS provider.
     * 
     * @param {string} dnsProvider 
     */
    setCurrentDnsProvider(dnsProvider) {
        this._currentDnsProvider = dnsProvider;
    }

    /**
     * Invoked whenever the selected DNS provider changes.
     * 
     * Will update the UI for inputting DNS provider specific options.
     * 
     * @param {*} sel SELECT element.
     */
    dnsProviderChanged(sel) {
        var selectedIndex = sel.selectedIndex;
        var dnsProviderInfo = this._dnsProviders[selectedIndex];
        console.log("api:",dnsProviderInfo);
    
        let optionsDiv = document.getElementById("ACME_DNSAPI_OPTIONS");
        optionsDiv.innerHTML = "";
        dnsProviderInfo.Opts.forEach((value, index, arr) => {
            const dl = document.createElement("dl");
            optionsDiv.appendChild(dl);
            // dt
            const dt = document.createElement("dt");
            dl.appendChild(dt);
            dt.setAttribute("onclick", "acme.toggleHelp("+ index +")");
            dt.setAttribute("style", "cursor:help");
            dt.innerText = value.Title + ":";
            // dd
            const dd = document.createElement("dd");
            dl.appendChild(dd);
            // dd->input
            const input = document.createElement("input");
            dd.appendChild(input);
            input.setAttribute("autocomplete", "off");
            if (/password/i.test(value.Name) || /token/i.test(value.Name) || /key/i.test(value.Name)) {
                input.setAttribute("type", "password");
                input.setAttribute("autocomplete", "new-password");
            } else {
                input.setAttribute("type", "text");
            }
            input.setAttribute("name", value.Name);
            input.setAttribute("spellcheck", "false");
            input.setAttribute("placeholder", value.Default);
            let currentValue = this._currentEnvironment[value.Name];
            if (currentValue) {
                input.setAttribute("value", currentValue);
            }
            if (value.Optional) {
                if (value.Default == "") {
                    input.setAttribute("placeholder", "(optional)");
                }
            } else {
                input.setAttribute("required", "required");
            }
            // blockquote
            const blockquote = document.createElement("blockquote");
            dl.appendChild(blockquote);
            blockquote.setAttribute("class", "inline_help acmeHelpinfo"+index);
            blockquote.setAttribute("style", "display: none;");
            const p1 = document.createElement("p");
            blockquote.appendChild(p1);
            var helpText = value.Name;
            if (value.Optional) {
                helpText += " (optional)";
            }
            p1.innerText = helpText;
            if (value.Description) {
                const p1 = document.createElement("p");
                blockquote.appendChild(p1);
                p1.innerText = value.Description;
            }
        });
    }

    /**
     * Show/hide help for a DNS provider option.
     * 
     * @param {number} optionIndex Option index.
     */
    toggleHelp(optionIndex) {
        $('.acmeHelpinfo'+optionIndex).toggle('slow');
    }
    
    /**
     * Create SWAL container for showing command execution output.
     * 
     * The "Done" button is initially disabled.
     * 
     * @param {string} title Title.
     * @param {string} confirmButtonText "Done" button text.
     * @param {function} onDone Function to invoke when user hits the "Done" button.
     */
    createSwal(title, confirmButtonText, onDone=null) {
        var self = this;
        swal({
            title:title,
            text:"<pre id='swaltext'></pre><hr>",
            html:true,
            animation:'none',
            showConfirmButton:true,
            confirmButtonText:confirmButtonText
        },
        function(isConfirm) {
            if (isConfirm) {
                self._nchanSubscriber.stop();
                $('.sweet-alert').hide('fast').removeClass('nchan');
                if (onDone) {
                    onDone();
                }
            }
        });
        $('.sweet-alert').addClass('nchan');
        swal.disableButtons();  
        this._nchanSubscriber.start();
    }

    /**
     * Enable the SWAL container "Done" button.
     */
    enableSwalButtons() {
        swal.enableButtons();
    }
}
