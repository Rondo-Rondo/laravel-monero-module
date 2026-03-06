import * as bip39 from 'bip39';
import {
    MoneroWalletConfig,
    MoneroNetworkType,
    MoneroWalletKeys
} from 'monero-ts';

async function main() {
    try {
        const mode = process.argv[2];

        if (mode === '--generate-monero') {
            const language = process.argv[3] || "English";

            const wallet = await MoneroWalletKeys.createWallet({
                networkType: MoneroNetworkType.MAINNET,
                language: language,
                proxyToWorker: false
            });

            console.log(JSON.stringify({
                success: true,
                address: await wallet.getPrimaryAddress(),
                spendKey: await wallet.getPrivateSpendKey(),
                viewKey: await wallet.getPrivateViewKey(),
                mnemonic: await wallet.getSeed(),
            }));
            return;
        }

        const bip39Mnemonic = mode;
        const bip39Passphrase = process.argv[3] || "";

        if (!bip39Mnemonic || !bip39.validateMnemonic(bip39Mnemonic)) {
            throw new Error("Invalid BIP39 mnemonic provided");
        }

        const seed = bip39.mnemonicToSeedSync(bip39Mnemonic, bip39Passphrase);
        let privateSpendKey = Buffer.from(seed.subarray(0, 32)).toString("hex");

        const config = new MoneroWalletConfig({
            networkType: MoneroNetworkType.MAINNET,
            privateSpendKey,
            proxyToWorker: false
        });

        const wallet = await MoneroWalletKeys.createWallet(config);

        console.log(JSON.stringify({
            success: true,
            address: await wallet.getPrimaryAddress(),
            spendKey: await wallet.getPrivateSpendKey(),
            viewKey: await wallet.getPrivateViewKey(),
            mnemonic: await wallet.getSeed(),
        }));
    } catch (error) {
        console.error(JSON.stringify({
            success: false,
            error: error.message || error.toString()
        }));
        process.exit(1);
    }
}

main();
