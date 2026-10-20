/**
 * Animate an HTML element with a light(house) characteristic
 * 
 * Bases on https://github.com/maciekmm/light-characteristics
 * Further information on light characterictics: https://en.wikipedia.org/wiki/Light_characteristic
 * 
 * @author Christoph Steindorff
 * @version 0.0.3
  */
class LightCharacteristic {

    // const COLOR_WHITE = 'white';
    COLOR_WHITE = '#FFFFCC'; // light yellow
    COLOR_RED = 'red';
    COLOR_GREEN = '#00FF00';
    COLOR_BLUE = 'blue';
    COLOR_YELLOW = 'yellow';
    COLOR_AMBER = '#FFBF00';
    COLOR_ORANGE = 'orange';
    COLOR_VIOLET = '#CC66FF';

    O_ON = (color = this.COLOR_WHITE) => {return {
        color: color,
        opacity: 1
    }}

    O_DIM = (color = this.COLOR_WHITE) => {return {
        opacity: 0.4,
        color: this.COLOR_WHITE
    }}

    O_OFF = {
        opacity: 0.001,
        color: this.COLOR_WHITE
    }

    LONG_FLASH_DURATION = 2500;
    FLASH_DURATION = 400;  // orginal 200ms were to short
    QUICK_FLASH_DURATION = 60*1000.0 / 70.0;
    VERY_QUICK_FLASH_DURATION = 60*1000.0 / 90.0;
    ULTRA_QUICK_FLASH_DURATION = 60*1000.0 / 160.0;
    DIT_DURATION = 200;

    MORSE_ALPHABET = {
        'a': '.-',    'b': '-...',  'c': '-.-.', 'd': '-..',
        'e': '.',     'f': '..-.',  'g': '--.',  'h': '....',
        'i': '..',    'j': '.---',  'k': '-.-',  'l': '.-..',
        'm': '- -',    'n': '-.',    'o': '---',  'p': '.--.',
        'q': '--.-',  'r': '.-.',   's': '...',  't': '-',
        'u': '..-',   'v': '...-',  'w': '.--',  'x': '-..-',
        'y': '-.--',  'z': '--..',  ' ': '/',
        '1': '.----', '2': '..---', '3': '...--', '4': '....-', 
        '5': '.....', '6': '-....', '7': '--...', '8': '---..', 
        '9': '----.', '0': '-----', 
    }

    /**
    * Animate background color of a HTML elemets according to light characteristics code
    * 
    * @param string code light characteristic abbreviation, e.g. "Fl(3) WRG 9s" or "Iso R 4s"
    * @param {*} elementId id of HTML element to animate
    */
   animateElement(code, elementId) {
   
        // "Gr" for Groups is not supported

        // expected format: [class]{(x{+y})} [color] {period} {height} {range}

        var arrCode;
        var lcClass;    // F, Fl, LFl, Q, VQ, UQ, IQ, IVQ, IUQ, Oc, Iso, FFl, Mo(x), Al, Al.Fl, Al.Oc, ...
        var lcGroup;    // (x) or (x+y): array of numbers, null without group
        var lcLongFlash = false; // trailing +LFl, e.g. Q(3)+LFl
        var lcInterrupted = false;
        var lcColor;    // W, G, R, Y, B
        var lcColors = [];
        var lcPeriod;   // e.g. 10s
        var lcHeight;   // e.g. 10m
        var lcRange;    // e.g. 15M
    

        //reduce multiple whitespaces to one blank
        code = code.replace(/\s+/g," ");

        // build array from code
        arrCode = code.split(" ");


        // we need at least the type and a color
        if (arrCode.length < 2) {
            throw "Light characteristic code to short";
        }


        // get class (1st part of code)

        // Interrupted?
        if (arrCode[0].toLowerCase() != "iso") {
            if (arrCode[0].charAt(0).toLowerCase() == 'i') {
                lcInterrupted = true;
                lcClass = arrCode[0].substring(1);
            } else {
                lcClass = arrCode[0];
            }
        } else {
            lcClass = arrCode[0];
        }

        // get group: (x), (x+y), optionally followed by +LFl
        if (lcClass.substring(0,2).toLowerCase() == 'mo') { // special case Morse Code
            var arrClass = lcClass.match(/^(.*)\((.)\)/);
            if (arrClass) {
                lcClass = arrClass[1];
                lcGroup = arrClass[2];
            } else {
                throw "wrong light characteristic code (Morse Code)";
            }
        } else {
            if (/\+LFl$/i.test(lcClass) && lcClass.length > 4) {
                lcLongFlash = true;
                lcClass = lcClass.slice(0, -4);
            }
            var arrClass = lcClass.match(/^(.*)\(([\d+]+)\)$/);
            if (arrClass) {
                lcClass = arrClass[1];
                lcGroup = arrClass[2].split('+').filter(x => x !== '').map(Number);
                if (lcGroup.length == 0 || lcGroup.includes(0)) {
                    throw "wrong group in light characteristic code";
                }
            } else {
                lcGroup = null;
            }
        }

        // get color (2nd part of code)
        lcColor = arrCode[1];
        const colorTokens = {
            'w': this.COLOR_WHITE, 'r': this.COLOR_RED, 'g': this.COLOR_GREEN, 'bu': this.COLOR_BLUE, 'b': this.COLOR_BLUE,
            'y': this.COLOR_YELLOW, 'am': this.COLOR_AMBER, 'or': this.COLOR_ORANGE, 'vi': this.COLOR_VIOLET, 'v': this.COLOR_VIOLET
        };
        for (const token of lcColor.match(/Bu|Vi|Or|Am|W|R|G|B|Y|V/gi) || []) {
            lcColors.push(colorTokens[token.toLowerCase()]);
        }
        if (lcColors.length == 0) {
            lcColors.push(this.COLOR_WHITE);
        }

        // Get optional period, height and range
        if (arrCode.length > 2) {
            for (var x=2; x < arrCode.length; x++) {
                console.log('arrcode[' + x + ']: ' + arrCode[x]);

                // period, e.g. "10s" or "4.5s"
                //if (arrCode[x].match(/^\d+s$/) !== null) {
                if (arrCode[x].match(/^\d+(\.\d+)?s$/) !== null) {
                    lcPeriod = arrCode[x].slice(0, -1) * 1000; // milliseconds
                }

                // height, e.g. "12m" or "12.5m"
                if (arrCode[x].match(/^\d+(\.\d+)?m$/) !== null) {
                    lcHeight = arrCode[x].slice(0, -1); 
                }

                // range, e.g. "15M" or "15.2M"
                if (arrCode[x].match(/^\d+(\.\d+)?M$/) !== null) {
                    lcRange = arrCode[x].slice(0, -1); 
                }
            }
        }

        // DEBUG CODE
        console.log('-----------------------');
        console.log('code: ' + code);
        console.log('ElementID: ' + elementId);
        console.log('Class: ' + lcClass);
        console.log('Group: ' + lcGroup);
        console.log('Interrupted: ' + lcInterrupted);
        console.log('Color: ' + lcColor);
        console.log('Colors: ' + lcColors);
        console.log('Period: ' + lcPeriod);
        console.log('Height: ' + lcHeight);
        console.log('Range: ' + lcRange);
        
   
       
       // parameters determined - let's start
        // "Al.Fl", "Al.Oc", "Al.Fl(2)" ...: the base characteristic with the colours taking turns;
        // the period covers one cycle through all colours.
        var animation;
        if (lcClass.substring(0, 3).toLowerCase() == 'al.') {
            var baseClass = lcClass.substring(3);
            var colorPeriod = lcPeriod / lcColors.length;
            if (baseClass == "F") { // Al.F: fixed, only the colour changes
                animation = this.alternating(lcPeriod, lcColors);
            } else {
                animation = this.combine(
                    ...lcColors.map(color => this.buildAnimation(baseClass, lcGroup, lcInterrupted, lcLongFlash, colorPeriod, color))
                );
            }
        } else if (lcClass == "Al") {
            animation = this.alternating(lcPeriod, lcColors); // Al
        } else {
            animation = this.buildAnimation(lcClass, lcGroup, lcInterrupted, lcLongFlash, lcPeriod, lcColors[0]);
        }
        this.animate(animation, elementId);

   }

    /**
     * Animation ([keyframes, duration]) of one single-colour characteristic
     */
    buildAnimation(cls, group, interrupted, longFlash, period, color) {
        const single = group != null && group.length == 1 ? group[0] : null;

        switch (cls) {
            case "F":       // F - Fixed
                return this.fixed(Infinity, color);

            case "Iso":     // Iso
                return this.iso(period, color);

            case "Oc":      // Oc, Oc(x), Oc(x+y)
                if (group == null) {
                    return this.occulting(period, color);
                }
                return this.compositeGroupOcculting(period, color, ...group);

            case "Fl":      // Fl, Fl(x), Fl(x+y), Fl(x)+LFl
                if (longFlash && single != null) {
                    return this.groupFlashingByLongFlash(period, single, color);
                }
                if (group == null) {
                    return this.flashing(period, color);
                }
                return this.compositeGroupFlashing(period, color, ...group);

            case "LFl":     // LFl, LFl(x), LFl(x+y)
                if (group == null) {
                    return this.longFlashing(period, color);
                }
                return this.compositeLongGroupFlashing(period, color, ...group);

            case "Q":       // Q, Q(x), IQ, IQ(x), Q(x)+LFl
                return this.quickClass(this.QUICK_FLASH_DURATION, 924, 50, group, single, interrupted, longFlash, period, color);

            case "VQ":      // VQ, VQ(x), IVQ, IVQ(x), VQ(x)+LFl
                return this.quickClass(this.VERY_QUICK_FLASH_DURATION, 660, 80, group, single, interrupted, longFlash, period, color);

            case "UQ":      // UQ, UQ(x), IUQ, IUQ(x), UQ(x)+LFl
                return this.quickClass(this.ULTRA_QUICK_FLASH_DURATION, 360, 160, group, single, interrupted, longFlash, period, color);

            case "FFl":     // FFl, FFl(x)
                return this.fixedAndFlashing(period, color, single == null ? 1 : single);

            case "Mo":      // Mo(x) - Morse Code
                if (group == null) {
                    throw "wrong light characteristic code (Morse Code)";
                }
                return this.morse(period, this.MORSE_ALPHABET[group.charAt(0).toLowerCase()], color);

            default:
                throw "unknown light characteristic class: " + cls;
        }
    }

    /**
     * Q, VQ and UQ share their logic and differ in flash duration only.
     * A bare Q/VQ/UQ ignores the period and flashes at the class's own default rate.
     */
    quickClass(flashDuration, defaultPeriod, minimumFrequency, group, single, interrupted, longFlash, period, color) {
        if (group == null) {
            return interrupted
                ? this.interruptedBlinking(period, flashDuration, color)
                : this.blinking(defaultPeriod, minimumFrequency, color);
        }
        if (longFlash && single != null) {
            return this.groupBlinkingByLongFlash(period, flashDuration, single, color);
        }
        if (interrupted) {
            return this.interruptedBlinking(period, flashDuration, color, group.reduce((a, b) => a + b));
        }
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, flashDuration / 2, flashDuration / 2, ...group);
    }

    convertToRelativeKeyFrames(keyframes, duration) {
        const relativeKeyFrames = []
        for(let keyframe of keyframes) {
            relativeKeyFrames.push({
                ...keyframe,
                offset: (keyframe.offset || 0) / duration
            })
        }
        return relativeKeyFrames
    }
    
    animate([keyframes, duration], elementId) {
        const lightElement = document.getElementById(elementId);
        lightElement.animate(this.convertToRelativeKeyFrames(keyframes, duration), {
            duration: duration,
            iterations: Infinity
        });
    }
    
    getAnimation([keyframes, duration]) {
        return this.convertToRelativeKeyFrames(keyframes, duration), {
            duration: duration,
            iterations: Infinity
        };
    }        
    
    




    // ---------------------------------------------------


    
    // Mo(x)
    morse(period, morseCode, color = this.COLOR_WHITE) {

        var lights = [];
        var overallTime = 0;
        var partTime;

        const light = this.O_ON(color);

        for(var x=0; x<morseCode.length; x++) {

            switch(morseCode.charAt(x)) {
                case ".":      // dit
                    partTime = this.DIT_DURATION;
                    break;
                    console.log('dit');
                case "-":       // dah
                    partTime = this.DIT_DURATION * 3;
                    console.log('dah');
                    break;
            }
            lights.push(this.getFixed(partTime, light)); // symbol (dit or dah)
            lights.push(this.getFixed(this.DIT_DURATION, this.O_OFF)); // pause between symbols
            overallTime += partTime;
        }

        if (period < (overallTime + (3 * this.DIT_DURATION))) {
            throw "period to short for morse code";
        }

        // final dark so we get period time
        lights.push(this.getFixed(period - overallTime, this.O_OFF));

        return this.combine(
            ...lights
        );
    }

    combine(...animations) {
        let finalDuration = 0
        let finalKeyframes = []
        for (let [keyframes, duration] of animations) {
            for (let keyframe of keyframes) {
                finalKeyframes.push(
                    {
                        ...keyframe,
                        offset: (keyframe.offset || 0) + finalDuration
                    }
                )
            }
            finalDuration += duration
        }
        return [finalKeyframes, finalDuration]
    }

    compositeGroup(period, groupOnIntensity, groupOffIntensity, onDuration, offDuration, ...groups) {
        const totalDims = groups.reduce((a, b) => a + b)
        const totalDimsDuration = (onDuration + offDuration) * totalDims
        if (totalDimsDuration > period) {
            throw "total duration of dims exceeds total period"
        }

        const longFlashDuration = (period - totalDimsDuration) / groups.length;

        const lights = []
        for (let inGroup of groups) {
            for (let i = 0; i < inGroup; i++) {
                lights.push(...[this.getFixed(onDuration, groupOnIntensity), this.getFixed(offDuration, groupOffIntensity)])
            }
            lights.push(...[this.getFixed(longFlashDuration, groupOffIntensity)])
        }


        return this.combine(
            ...lights,
        )
    }


    getFixed(duration = Infinity, light) {
        const keyframes = [
            {
                opacity: light.opacity,
                backgroundColor: light.color,
                easing: 'steps(1, end)',
                // easing: 'cubic-bezier(0.8, 0, 1,0)'
            },
        ]
        return [keyframes, duration]
    }


    //F
    fixed(duration = Infinity, color = this.COLOR_WHITE) {
        
        const light = this.O_ON(color);
        const keyframes = [
            {
                opacity: light.opacity,
                backgroundColor: light.color,
                easing: 'steps(1, end)',
                // easing: 'cubic-bezier(0.8, 0, 1,0)'
            },
        ]
        return this.getFixed(duration, light);
    }


    //Iso
    iso(period, color = this.COLOR_WHITE) {

        const light = this.O_ON(color);
        return this.combine(
            this.getFixed(0.5 * period, light),
            this.getFixed(0.5 * period, this.O_OFF)
        );
    }

    //Oc
    occulting(period, color = this.COLOR_WHITE) {
        if (period < 1500) {
            throw "period of occulting light cannot be shorter than 1.5s"
        }
        const light = this.O_ON(color);
        return this.combine(
            this.getFixed(0.7 * period, light),
            this.getFixed(0.3 * period, this.O_OFF)
        );
    }

    //Oc(x)
    groupOcculting(period, color = this.COLOR_WHITE, dims = 2) {
        return this.compositeGroupOcculting(period, color, dims)
    }


    //Fl
    flashing(period, color = this.COLOR_WHITE) {
        return this.compositeGroupFlashing(period, color, 1)
    }

    //Fl(x)
    groupFlashing(period, color = this.COLOR_WHITE, inGroup) {
        return this.compositeGroupFlashing(period, color, inGroup)
    }


    //LFl
    longFlashing(period, color = this.COLOR_WHITE) {
        if (period < 3000) {
            throw "LFl period cannot be shorter than 3s"
        }
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, this.LONG_FLASH_DURATION, 3000 - this.LONG_FLASH_DURATION, 1)
    }

    //LFl(x)
    groupLongFlashing(period, color = this.COLOR_WHITE, inGroup) {
        if (period < inGroup * 2 * this.LONG_FLASH_DURATION) {
            throw "LFl(x) period is to short"
        }
        return this.compositeLongGroupFlashing(period, color, inGroup)
    }


    //Q
    quick(period = 120, color = this.COLOR_WHITE) {
        return this.blinking(period, 50, color)
    }

    //Q(x)
    groupQuick(period, color = this.COLOR_WHITE, flashes = 1) {
        return this.groupBlinking(period, this.QUICK_FLASH_DURATION, flashes, color)
    }

    //IQ
    interruptedQuick(period, color = this.COLOR_WHITE) {
        return this.interruptedBlinking(period, this.QUICK_FLASH_DURATION, color)
    }

    //IQ(x)
    interruptedGroupQuick(period, color = this.COLOR_WHITE, flashes) {
        return this.interruptedBlinking(period, this.QUICK_FLASH_DURATION, color, flashes)
    }


    //VQ
    veryQuick(period = 180, color = this.COLOR_WHITE) {
        return this.blinking(period, 80, color)
    }

    //VQ(x)
    groupVeryQuick(period, color = this.COLOR_WHITE, flashes = 1) {
        return this.groupBlinking(period, this.VERY_QUICK_FLASH_DURATION, flashes, color)
    }

    //IVQ
    interruptedVeryQuick(period, color = this.COLOR_WHITE) {
        return this.interruptedBlinking(period, this.VERY_QUICK_FLASH_DURATION, color)
    }

    //IVQ(x)
    interruptedGroupVeryQuick(period, color = this.COLOR_WHITE, flashes) {
        return this.interruptedBlinking(period, this.VERY_QUICK_FLASH_DURATION, color, flashes)
    }


    //FFl(x)
    fixedAndFlashing(period, color = this.COLOR_WHITE, inGroup = 1) {
        const flashes = []

        for (let i = 0; i < inGroup; i++) {
            flashes.push(...[this.getFixed(this.FLASH_DURATION, this.O_ON(color)), this.getFixed(this.FLASH_DURATION, this.O_DIM(color))])
        }

        const durationOfFlashes = this.FLASH_DURATION * 2 * inGroup;
        if (durationOfFlashes > period) {
            throw "duration of flashes exceeds total period"
        }

        return this.combine(
            ...flashes,
            this.getFixed(period - durationOfFlashes, this.O_DIM(color))
        )
    }

    // Al
    alternating(period, colors) {
        const singleColorPeriod = period / colors.length
        const lights = []
        for(let color of colors) {
            lights.push(this.getFixed(singleColorPeriod, this.O_ON(color)))
        }

        return this.combine(
            ...lights
        )
    }


    // ------------------------------------------------------------------------------------------------------------------------------


    //Fl(x+y)
    compositeGroupFlashing(period, color = this.COLOR_WHITE, ...groups) {
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, this.FLASH_DURATION, this.FLASH_DURATION, ...groups)
    }

    //LFl(x+y)
    compositeLongGroupFlashing(period, color = this.COLOR_WHITE, ...groups) {
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, this.LONG_FLASH_DURATION, this.LONG_FLASH_DURATION, ...groups)
    }


    //Oc(x+y)
    compositeGroupOcculting(period, color = this.COLOR_WHITE, ...dimGroups) {
        return this.compositeGroup(period, this.O_OFF, this.O_ON(color), this.FLASH_DURATION, this.FLASH_DURATION, ...dimGroups)
    }

    //LFl(x+y)
    compositeGroupLongFlashing(period, ...groups) {
        if(period < 3000) {
            throw "LFl period cannot be shorter than 3s"
        }
        return this.compositeGroup(period, this.O_ON(this.COLOR_WHITE), this.O_OFF, 2500, 500, ...groups)
    }


    //Fl(x)+LFl
    groupFlashingByLongFlash(period, flashes, color = this.COLOR_WHITE) {
        return this.groupBlinkingByLongFlash(period, this.FLASH_DURATION * 2, flashes, color)
    }

    //Q(x)+LFI
    groupQuickByLongFlash(period, flashes, color = this.COLOR_WHITE) {
        return this.groupBlinkingByLongFlash(period, this.QUICK_FLASH_DURATION, flashes, color)
    }


    //VQ(x)+LFI
    groupVeryQuickByLongFlash(period, flashes, color = this.COLOR_WHITE) {
        return this.groupBlinkingByLongFlash(period, this.VERY_QUICK_FLASH_DURATION, flashes, color)
    }




    blinking(period, minimumFrequency, color = this.COLOR_WHITE) {
        if(period > 60*1000 / minimumFrequency) {
            throw "quick's frequency must be > "+minimumFrequency + " blinks/minute"
        }

        return this.combine(
            this.getFixed(0.5*period, this.O_OFF),
            this.getFixed(0.5*period, this.O_ON(color))
        )
    }

    groupBlinking(period, flashDuration, flashes = 1, color = this.COLOR_WHITE) {
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, flashDuration/2, flashDuration/2, flashes)
    }

    interruptedBlinking(period, flashDuration, color = this.COLOR_WHITE, flashes = 8) {
        const dimStageLength = period - flashDuration * flashes;
        if(dimStageLength < 3000) {
            throw "dim stage in interrupted light must of length > 3s, but is " + dimStageLength
        }
        return this.compositeGroup(period, this.O_ON(color), this.O_OFF, flashDuration/2, flashDuration/2, flashes)
    }

    groupBlinkingByLongFlash(period, flashDuration, flashes, color = this.COLOR_WHITE) {
        const groupBlinkingDuration = flashDuration*flashes;
        const longFlashDuration = (period - groupBlinkingDuration) / 2;

        return this.combine(
            this.groupBlinking(groupBlinkingDuration, flashDuration, flashes, color),
            this.getFixed(longFlashDuration, this.O_ON(color)),
            this.getFixed(longFlashDuration, this.O_OFF)
        )
    }



}