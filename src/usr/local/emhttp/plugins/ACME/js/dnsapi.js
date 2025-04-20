// Courtesy of https://github.com/yurt-page/acmesh-parse-dnsapi-info
class DnsApiInfo {
    /** @type string */
    Id = ''
    /** @type string */
    Name = ''
    /** @type string */
    Description = ''
    /** @type boolean */
    Deprecated = false
    /** @type string */
    Domains = ''
    /** @type string */
    Site = ''
    /** @type string */
    Docs = ''
    /** @type string */
    OptsTitle = ''
    /** @type DnsApiInfoOpt[] */
    Opts = []
    /** @type string */
    OptsAltTitle = ''
    /** @type DnsApiInfoOpt[] */
    OptsAlt = []
    /** @type string */
    Issues = ''
    /** @type string */
    Author = ''
}

class DnsApiInfoOpt {
    /** @type string */
    Name = ''
    /** @type string */
    Title = ''
    /** @type string */
    Description = ''
    /** @type string */
    Default = ''
    /** @type boolean */
    Optional = false
}

/**
 * @param infoFileText {string}
 * @returns {DnsApiInfo[]}
 */
export function parseFile(infoFileText) {
    let infoFileLines = infoFileText.split('\n')
    let infos = []
    let startIdx = 0
    for (let i = 1; i < infoFileLines.length; i++) {
        if (infoFileLines[i] == '') {
            if (i - startIdx > 2) {
                let infoLines = infoFileLines.slice(startIdx, i)
                let info = parseDnsApiInfoLines(infoLines)
                infos.push(info)
            }
            startIdx = i + 1
        }
    }
    return infos
}

/**
 * @param {string[]} lines
 * @returns {DnsApiInfo|null}
 */
function parseDnsApiInfoLines(lines) {
    let info = new DnsApiInfo()
    info.Id = lines.shift()
    info.Name = lines.shift()
    info.Description = fieldMultiLines(lines, '').substring(1)

    info.Deprecated = info.Description.includes('Deprecated. ')
    if (info.Deprecated) {
        info.Description = info.Description.replace('Deprecated. ', '')
    }

    let optsField = getFieldVal(lines, 'Options:')
    let [optsTitle, opts] = parseOpts(optsField)
    info.OptsTitle = optsTitle
    info.Opts = opts
    let optsAltField = getFieldVal(lines, 'OptionsAlt:')
    let [optsAltTitle, optsAlt] = parseOpts(optsAltField)
    info.OptsAltTitle = optsAltTitle
    info.OptsAlt = optsAlt

    info.Domains = getFieldVal(lines, 'Domains:')
    info.Site = getFieldVal(lines, 'Site:')
    info.Docs = getFieldVal(lines, 'Docs:')
    info.Issues = getFieldVal(lines, 'Issues:')
    info.Author = getFieldVal(lines, 'Author:')

    info.Site = toUrl(info.Site)
    info.Docs = toUrl(info.Docs)
    info.Issues = toUrl(info.Issues)
    return info
}

/**
 * @param {string} infoText
 * @returns {DnsApiInfo|null}
 */
function parseDnsApiInfo(infoText) {
    let lines = infoText.split('\n')
    return parseDnsApiInfoLines(lines);
}

/**
 * @param {string} options
 * @return {[string, DnsApiInfoOpt[]]}
 */
function parseOpts(options) {
    if (!options) {
        return ['', []]
    }
    let opts = []
    let optLines = options.split('\n')
    let optsTitle = optLines.shift()
    for (let optLine of optLines) {
        let posName = optLine.indexOf(' ')
        if (posName <= 0) {
            continue
        }
        let opt = new DnsApiInfoOpt()
        opt.Name = optLine.substring(0, posName)
        let posTitle = optLine.indexOf('.')
        if (posTitle <= 0) {
            opt.Title = optLine.substring(posName + 1)
        } else {
            opt.Title = optLine.substring(posName + 1, posTitle)
            opt.Description = optLine.substring(posTitle)
            opt.Optional = opt.Description.includes(' Optional.')
            if (opt.Optional) {
                opt.Description = opt.Description.replace(' Optional.', '')
            }
            let defaultPos = opt.Description.indexOf(' Default: "')
            if (defaultPos >= 0) {
                opt.Optional = true
                let defaultPosEnd = opt.Description.indexOf('".', defaultPos + 1)
                opt.Default = opt.Description.substring(defaultPos + ' Default: "'.length, defaultPosEnd)
                opt.Description = opt.Description.substring(0, defaultPos)
            }
            if (opt.Description.startsWith('. ')) {
                opt.Description = opt.Description.substring(2)
            } else if (opt.Description == '.') {
                opt.Description = ''
            }
        }
        opts.push(opt)
    }
    return [optsTitle, opts]
}

/**
 * @param lines {string[]}
 * @param fieldName {string}
 * @returns {string}
 */
function getFieldVal(lines, fieldName) {
    for (let i = 0; i < lines.length; i++) {
        if (lines[i].startsWith(fieldName)) {
            let firstVal = lines[i].substring(fieldName.length).trim()
            let nextLines = lines.slice(i + 1)
            return fieldMultiLines(nextLines, firstVal)
        }
    }
    return ''
}

function fieldMultiLines(lines, fieldVal) {
    while (lines.length > 0) {
        if (!lines[0].startsWith(' ')) {
            break
        }
        let line = lines.shift()
        fieldVal += '\n' + line.trim()
    }
    return fieldVal
}

function toUrl(url) {
    return url && !url.startsWith('https://') ? 'https://' + url : url;
}
