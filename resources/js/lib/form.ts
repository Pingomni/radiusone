import type { ChangeEvent } from 'react';

type FormLike<T> = {
    data: T;
    errors: Partial<Record<keyof T | string, string>>;
    setData: (key: keyof T, value: never) => void;
};

/** Props wiring a text/select/textarea field to an Inertia useForm field: name, value, onChange, error. */
export function bind<T extends object>(form: FormLike<T>, key: keyof T & string) {
    return {
        name: key,
        value: String(form.data[key] ?? ''),
        error: form.errors[key],
        onChange: (event: ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => form.setData(key, event.target.value as never),
    };
}

/** Props wiring a checkbox to a boolean useForm field. */
export function bindCheck<T extends object>(form: FormLike<T>, key: keyof T & string) {
    return {
        name: key,
        checked: Boolean(form.data[key]),
        onChange: (event: ChangeEvent<HTMLInputElement>) => form.setData(key, event.target.checked as never),
    };
}
