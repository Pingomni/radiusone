import QRCode from 'qrcode';
import { useEffect, useState } from 'react';

/** QR code + manual key for enrolling an authenticator app (generated locally, never sent anywhere). */
export default function TwoFactorSetup({ secret, uri }: { secret: string; uri: string }) {
    const [svg, setSvg] = useState('');
    useEffect(() => {
        QRCode.toString(uri, { type: 'svg', errorCorrectionLevel: 'M', margin: 3 }).then(setSvg, () => setSvg(''));
    }, [uri]);
    return (
        <div className="two-factor-setup">
            <div className="qr-card">
                <div className="qr-code" role="img" aria-label="Two-factor setup QR code">
                    {svg && <img src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`} alt="" width={180} height={180} />}
                </div>
                <span>Scan with your authenticator app</span>
            </div>
            <label>
                Manual setup key
                <input readOnly value={secret.match(/.{1,4}/g)?.join(' ') ?? secret} />
            </label>
            <label className="span2">
                Authenticator URI
                <textarea readOnly value={uri} />
                <small>Use this if your password manager supports otpauth import.</small>
            </label>
            <small className="span2">After scanning, enter the 6-digit code your app shows.</small>
        </div>
    );
}
