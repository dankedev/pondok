import { inspect } from 'node:util';

try {
    const { build } = await import('vite');

    await build();
} catch (error) {
    console.error(inspect(error, {
        depth: 10,
        getters: true,
        maxArrayLength: null,
    }));

    if (error instanceof Error && error.cause) {
        console.error('Caused by:', inspect(error.cause, {
            depth: 10,
            getters: true,
            maxArrayLength: null,
        }));
    }

    process.exitCode = 1;
}
